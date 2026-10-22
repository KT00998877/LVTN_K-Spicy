"""
Hybrid Retrieval module - combines BM25 and Vector search.
"""
from __future__ import annotations

import math
import re
from collections import Counter
from dataclasses import dataclass, field
from typing import Any, Optional

from loguru import logger

from knowledge_base.loader import Chunk, KnowledgeBaseLoader


@dataclass
class RetrievedChunk:
    """A retrieved chunk with score."""
    chunk: Chunk
    score: float
    rank: int
    source: str = ""


class BM25Retriever:
    """
    BM25 (Best Matching 25) keyword-based retriever.
    
    BM25 is a ranking function used in information retrieval.
    It ranks documents based on term frequency and inverse document frequency.
    """
    
    def __init__(
        self,
        chunks: list[Chunk],
        k1: float = 1.5,
        b: float = 0.75,
    ):
        """
        Initialize BM25 retriever.
        
        Args:
            chunks: List of chunks to index
            k1: Term frequency saturation parameter (default 1.5)
            b: Document length normalization (default 0.75)
        """
        self.chunks = chunks
        self.k1 = k1
        self.b = b
        
        # Build index
        self._doc_lengths: list[int] = []
        self._avg_doc_length: float = 0.0
        self._doc_term_freqs: list[dict[str, int]] = []
        self._idf: dict[str, float] = {}
        self._vocab: set[str] = set()
        
        self._build_index()
    
    def _tokenize(self, text: str) -> list[str]:
        """Tokenize Vietnamese text into words."""
        # Simple tokenizer: lowercase, split by whitespace and punctuation
        text = text.lower()
        # Keep Vietnamese characters
        words = re.findall(r'[\w\u00C0-\u024F]+', text, re.UNICODE)
        return words
    
    def _build_index(self) -> None:
        """Build BM25 index from chunks."""
        if not self.chunks:
            return
        
        # Calculate document lengths
        all_terms: set[str] = set()
        doc_count = len(self.chunks)
        
        for chunk in self.chunks:
            # Use text field for indexing
            terms = self._tokenize(chunk.text)
            self._doc_term_freqs.append(Counter(terms))
            self._doc_lengths.append(len(terms))
            all_terms.update(terms)
        
        self._avg_doc_length = sum(self._doc_lengths) / len(self._doc_lengths) if self._doc_lengths else 0
        self._vocab = all_terms
        
        # Calculate IDF for each term
        for term in all_terms:
            # Number of documents containing the term
            doc_freq = sum(1 for doc_tf in self._doc_term_freqs if term in doc_tf)
            # BM25 IDF formula
            self._idf[term] = math.log((doc_count - doc_freq + 0.5) / (doc_freq + 0.5) + 1)
        
        logger.info(f"BM25 index built: {len(self.chunks)} docs, {len(self._vocab)} terms")
    
    def get_search_keywords(self, query: str) -> list[str]:
        """Extract search keywords from query."""
        return self._tokenize(query)
    
    def score(self, query: str, doc_index: int) -> float:
        """
        Calculate BM25 score for a document given a query.
        
        Args:
            query: Query string
            doc_index: Index of the document
            
        Returns:
            BM25 score
        """
        query_terms = self._tokenize(query)
        doc_tf = self._doc_term_freqs[doc_index]
        doc_len = self._doc_lengths[doc_index]
        
        score = 0.0
        
        for term in query_terms:
            if term not in self._idf:
                continue
            
            tf = doc_tf.get(term, 0)
            idf = self._idf[term]
            
            # BM25 scoring formula
            numerator = tf * (self.k1 + 1)
            denominator = tf + self.k1 * (1 - self.b + self.b * doc_len / self._avg_doc_length)
            
            score += idf * (numerator / denominator)
        
        return score
    
    def rank(self, query: str, top_k: int = 5) -> list[RetrievedChunk]:
        """
        Rank documents by BM25 score.
        
        Args:
            query: Query string
            top_k: Number of top results to return
            
        Returns:
            List of RetrievedChunk objects
        """
        if not self.chunks:
            return []
        
        # Calculate scores for all documents
        scores = []
        for i, chunk in enumerate(self.chunks):
            s = self.score(query, i)
            if s > 0:
                scores.append((s, i, chunk))
        
        # Sort by score descending
        scores.sort(key=lambda x: x[0], reverse=True)
        
        # Return top_k
        results = []
        for rank, (score, _, chunk) in enumerate(scores[:top_k], 1):
            results.append(RetrievedChunk(
                chunk=chunk,
                score=score,
                rank=rank,
                source=chunk.type,
            ))
        
        return results


class HybridRetriever:
    """
    Hybrid retriever combining BM25, Vector, and RRF fusion.
    
    Supports:
    - BM25 only (use_vector=False)
    - Vector only (use_bm25=False, use_vector=True)
    - Hybrid RRF (both enabled)
    """
    
    def __init__(
        self,
        chunks: Optional[list[Chunk]] = None,
        use_bm25: bool = True,
        use_vector: bool = True,
        top_k: int = 5,
        rrf_k: int = 60,
        embeddings_path: Optional[str] = None,
    ):
        """
        Initialize hybrid retriever.
        
        Args:
            chunks: List of chunks to search
            use_bm25: Enable BM25 search
            use_vector: Enable vector search (requires embeddings)
            top_k: Default number of results
            rrf_k: RRF smoothing constant (default 60)
            embeddings_path: Path to embeddings file
        """
        self.use_bm25 = use_bm25
        self.use_vector = use_vector
        self.top_k = top_k
        self.rrf_k = rrf_k
        
        self._bm25: Optional[BM25Retriever] = None
        self._vector: Any = None
        
        # Late import to avoid circular dependency
        if use_vector:
            try:
                from core.vector_retriever import VectorRetriever
                self._vector_retriever_class = VectorRetriever
                self._embeddings_path = embeddings_path
            except ImportError:
                logger.warning("VectorRetriever not available")
                self.use_vector = False
        
        if chunks:
            self.load_chunks(chunks)
    
    def load_chunks(self, chunks: list[Chunk]) -> None:
        """Load chunks and build indices."""
        if self.use_bm25:
            self._bm25 = BM25Retriever(chunks)
            logger.info(f"Loaded {len(chunks)} chunks for BM25")
        
        if self.use_vector and hasattr(self, '_vector_retriever_class'):
            try:
                self._vector = self._vector_retriever_class(
                    chunks=chunks,
                    embeddings_path=self._embeddings_path,
                )
                logger.info(f"Loaded {len(chunks)} chunks for Vector search")
            except Exception as e:
                logger.warning(f"Failed to load vector retriever: {e}")
                self.use_vector = False
    
    async def retrieve(
        self,
        query: str,
        top_k: Optional[int] = None,
        method: str = "hybrid",  # "bm25", "vector", "hybrid"
    ) -> list[RetrievedChunk]:
        """
        Retrieve relevant chunks for a query.
        
        Args:
            query: Query string (Vietnamese)
            top_k: Number of results (uses default if not specified)
            method: Retrieval method ("bm25", "vector", "hybrid")
            
        Returns:
            List of RetrievedChunk objects
        """
        k = top_k or self.top_k
        
        # Override methods based on enabled flags
        if method == "hybrid" and not self.use_vector:
            method = "bm25"
        if method == "hybrid" and not self.use_bm25:
            method = "vector"
        
        if method == "bm25":
            if self._bm25:
                return self._bm25.rank(query, k)
            return []
        
        if method == "vector":
            if self._vector:
                results = self._vector.search(query, k)
                return self._vector.to_retrieved_chunks(results)
            return []
        
        # Hybrid RRF
        bm25_results = []
        vector_results = []
        
        if self.use_bm25 and self._bm25:
            bm25_results = self._bm25.rank(query, k * 2)
        
        if self.use_vector and self._vector:
            vec_raw = self._vector.search(query, k * 2)
            vector_results = self._vector.to_retrieved_chunks(vec_raw)
        
        return self._rrf_fusion(bm25_results, vector_results, k)
    
    def _rrf_fusion(
        self,
        bm25_results: list[RetrievedChunk],
        vector_results: list[RetrievedChunk],
        top_k: int,
    ) -> list[RetrievedChunk]:
        """
        Reciprocal Rank Fusion.
        
        RRF score = sum(1 / (k + rank_i)) over all ranking lists
        """
        chunk_scores: dict[str, float] = {}
        chunk_objects: dict[str, RetrievedChunk] = {}
        
        # Process BM25 ranks
        for ret in bm25_results:
            cid = ret.chunk.chunk_id
            rrf_score = 1.0 / (self.rrf_k + ret.rank)
            chunk_scores[cid] = chunk_scores.get(cid, 0) + rrf_score
            chunk_objects[cid] = ret
        
        # Process Vector ranks
        for ret in vector_results:
            cid = ret.chunk.chunk_id
            rrf_score = 1.0 / (self.rrf_k + ret.rank)
            chunk_scores[cid] = chunk_scores.get(cid, 0) + rrf_score
            if cid not in chunk_objects:
                chunk_objects[cid] = ret
            else:
                # Mark as hybrid source
                existing = chunk_objects[cid]
                if existing.source != ret.source:
                    # Use combined source
                    chunk_objects[cid] = RetrievedChunk(
                        chunk=existing.chunk,
                        score=chunk_scores[cid],
                        rank=0,  # Will be reassigned
                        source=f"{existing.source}+{ret.source}",
                    )
        
        # Sort by RRF score
        sorted_chunks = sorted(
            chunk_scores.items(),
            key=lambda x: x[1],
            reverse=True,
        )
        
        # Build results
        results = []
        for rank, (cid, score) in enumerate(sorted_chunks[:top_k], 1):
            ret = chunk_objects[cid]
            results.append(RetrievedChunk(
                chunk=ret.chunk,
                score=score,
                rank=rank,
                source=ret.source,
            ))
        
        return results
    
    def get_context_for_prompt(
        self,
        query: str,
        top_k: Optional[int] = None,
    ) -> str:
        """Get formatted context string for RAG prompt."""
        import asyncio
        results = asyncio.run(self.retrieve(query, top_k))
        
        if not results:
            return ""
        
        context_parts = []
        for i, result in enumerate(results, 1):
            chunk = result.chunk
            context_parts.append(
                f"[{i}] ({chunk.type}) {chunk.text}"
            )
        
        return "\n\n".join(context_parts)


# Factory function
def create_retriever(
    kb_path: Optional[str] = None,
    top_k: int = 5,
    use_bm25: bool = True,
    use_vector: bool = True,
    embeddings_path: Optional[str] = None,
) -> HybridRetriever:
    """
    Create a hybrid retriever with knowledge base chunks.
    
    Args:
        kb_path: Path to knowledge base
        top_k: Number of results
        use_bm25: Enable BM25
        use_vector: Enable vector search
        embeddings_path: Path to embeddings file
        
    Returns:
        Configured HybridRetriever
    """
    loader = KnowledgeBaseLoader(kb_path) if kb_path else KnowledgeBaseLoader()
    chunks = loader.load_chunks()
    
    return HybridRetriever(
        chunks=chunks,
        top_k=top_k,
        use_bm25=use_bm25,
        use_vector=use_vector,
        embeddings_path=embeddings_path,
    )

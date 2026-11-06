"""
Vector Retriever Module.

Implements semantic search using pre-computed embeddings.
Embeddings are from multilingual-e5-small (384 dim).
"""
from __future__ import annotations

import json
import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional

from loguru import logger

from core.retrieval import RetrievedChunk
from knowledge_base.loader import Chunk


@dataclass
class VectorResult:
    """Vector search result."""
    chunk: Chunk
    score: float
    rank: int


class VectorRetriever:
    """
    Vector retriever using cosine similarity.
    
    Uses pre-computed embeddings (multilingual-e5-small, 384 dim).
    Encodes each query with the same E5 model used for stored embeddings.
    """
    
    DEFAULT_EMBEDDINGS_PATH = str(Path(__file__).resolve().parents[1] / "knowledge_base" / "embeddings" / "embeddings.jsonl")
    EMBEDDING_DIM = 384
    MODEL_ID = "intfloat/multilingual-e5-small"
    
    def __init__(
        self,
        chunks: list[Chunk],
        embeddings_path: Optional[str] = None,
    ):
        """
        Initialize vector retriever.
        
        Args:
            chunks: List of chunks to index
            embeddings_path: Path to embeddings.jsonl
        """
        self.chunks = chunks
        self.embeddings_path = embeddings_path or self.DEFAULT_EMBEDDINGS_PATH
        
        # Load embeddings
        self._chunk_embeddings: dict[str, list[float]] = {}
        self._chunk_norms: dict[str, float] = {}
        self._query_model = None
        
        self._load_embeddings()
    
    def _load_embeddings(self) -> None:
        """Load embeddings from file."""
        path = Path(self.embeddings_path)
        
        if not path.exists():
            logger.warning(f"Embeddings file not found: {path}")
            return
        
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                data = json.loads(line)
                chunk_id = data.get("chunk_id")
                vector = data.get("vector")
                
                if chunk_id and vector:
                    self._chunk_embeddings[chunk_id] = vector
                    self._chunk_norms[chunk_id] = self._norm(vector)
        
        matched = sum(1 for c in self.chunks if c.chunk_id in self._chunk_embeddings)
        logger.info(
            f"Loaded {len(self._chunk_embeddings)} embeddings, "
            f"{matched}/{len(self.chunks)} chunks matched"
        )
    
    def _norm(self, vec: list[float]) -> float:
        """Compute L2 norm of a vector."""
        return math.sqrt(sum(x * x for x in vec))
    
    def _cosine_similarity(
        self,
        vec_a: list[float],
        vec_b: list[float],
    ) -> float:
        """Compute cosine similarity between two vectors."""
        norm_a = self._norm(vec_a)
        norm_b = self._norm(vec_b)
        
        if norm_a == 0 or norm_b == 0:
            return 0.0
        
        dot = sum(a * b for a, b in zip(vec_a, vec_b))
        return dot / (norm_a * norm_b)
    
    def _embed_query(self, query: str) -> list[float]:
        """Encode a question directly in the stored E5 document vector space."""
        if self._query_model is None:
            try:
                from sentence_transformers import SentenceTransformer
            except ImportError as exc:
                raise RuntimeError("Install sentence-transformers for vector retrieval") from exc
            self._query_model = SentenceTransformer(self.MODEL_ID)
        vector = self._query_model.encode("query: " + query, normalize_embeddings=True)
        if len(vector) != self.EMBEDDING_DIM:
            raise ValueError("Query embedding dimension differs from stored vectors")
        return list(vector)
    
    def search(
        self,
        query: str,
        top_k: int = 5,
    ) -> list[VectorResult]:
        """
        Search for relevant chunks using vector similarity.
        
        Args:
            query: Query string
            top_k: Number of results to return
            
        Returns:
            List of VectorResult
        """
        if not self._chunk_embeddings:
            return []
        
        query_vec = self._embed_query(query)
        query_norm = self._norm(query_vec)
        
        if query_norm == 0:
            return []
        
        # Score all chunks
        scored = []
        for chunk in self.chunks:
            emb = self._chunk_embeddings.get(chunk.chunk_id)
            if not emb:
                continue
            
            score = self._cosine_similarity(query_vec, emb)
            if score > 0:
                scored.append((score, chunk))
        
        # Sort descending
        scored.sort(key=lambda x: x[0], reverse=True)
        
        results = []
        for rank, (score, chunk) in enumerate(scored[:top_k], 1):
            results.append(VectorResult(
                chunk=chunk,
                score=score,
                rank=rank,
            ))
        
        return results
    
    def to_retrieved_chunks(self, results: list[VectorResult]) -> list[RetrievedChunk]:
        """Convert VectorResult list to RetrievedChunk list."""
        return [
            RetrievedChunk(
                chunk=r.chunk,
                score=r.score,
                rank=r.rank,
                source=r.chunk.type,
            )
            for r in results
        ]

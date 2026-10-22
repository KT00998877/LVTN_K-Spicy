"""
Knowledge Base loader module.
Loads and parses knowledge base data from JSONL files.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Optional

from loguru import logger


@dataclass
class Chunk:
    """A chunk of knowledge base data."""
    chunk_id: str
    text: str
    type: str  # faq, menu_item, category, policy, etc.
    source: str
    source_id: Optional[str] = None
    parent_id: Optional[str] = None
    metadata: dict[str, Any] = field(default_factory=dict)
    
    def to_dict(self) -> dict[str, Any]:
        """Convert to dictionary."""
        return {
            "chunk_id": self.chunk_id,
            "text": self.text,
            "type": self.type,
            "source": self.source,
            "source_id": self.source_id,
            "parent_id": self.parent_id,
            "metadata": self.metadata,
        }


@dataclass
class FAQData:
    """FAQ data structure."""
    id: str
    type: str
    text: str
    question: str
    answer: str
    category: str
    search_keywords: list[str] = field(default_factory=list)
    metadata: dict[str, Any] = field(default_factory=dict)


@dataclass
class MenuItem:
    """Menu item data structure."""
    id: str
    name: str
    slug: str
    description: Optional[str]
    category: str
    price: int
    price_formatted: str
    size: Optional[str]
    spicy_level: int
    spicy_label: str
    ingredients: list[str] = field(default_factory=list)
    preparation_minutes: int = 10
    search_keywords: list[str] = field(default_factory=list)


@dataclass
class RestaurantInfo:
    """Restaurant information."""
    brand: str
    name: str
    slogan: str
    description: str
    phone: str
    address: str
    open_hours: str
    max_km: int
    max_guests: int
    min_advance_minutes: int


class KnowledgeBaseLoader:
    """
    Loads knowledge base from JSONL files.
    
    Usage:
        loader = KnowledgeBaseLoader()
        chunks = loader.load_chunks()
        faq = loader.load_faq()
        menu = loader.load_menu()
    """
    
    def __init__(self, kb_path: Optional[str] = None):
        """
        Initialize loader.
        
        Args:
            kb_path: Path to knowledge base directory. 
                    Defaults to knowledge_base/ relative to this file.
        """
        if kb_path:
            self.kb_path = Path(kb_path)
        else:
            # Default to knowledge_base/ relative to project root
            self.kb_path = Path(__file__).parent.parent / "knowledge_base"
        
        self._chunks: Optional[list[Chunk]] = None
        self._faq: Optional[list[FAQData]] = None
        self._menu: Optional[list[MenuItem]] = None
        self._restaurant: Optional[RestaurantInfo] = None
        
        logger.info(f"KnowledgeBaseLoader initialized with path: {self.kb_path}")
    
    @property
    def chunks_path(self) -> Path:
        """Path to chunks file."""
        return self.kb_path / "chunks" / "chunks.jsonl"
    
    @property
    def faq_path(self) -> Path:
        """Path to FAQ file."""
        return self.kb_path / "raw" / "faq.jsonl"
    
    @property
    def menu_path(self) -> Path:
        """Path to menu file."""
        return self.kb_path / "raw" / "menu.jsonl"
    
    @property
    def restaurant_path(self) -> Path:
        """Path to restaurant info file."""
        return self.kb_path / "raw" / "restaurant.jsonl"
    
    def load_chunks(self, force_reload: bool = False) -> list[Chunk]:
        """
        Load all chunks from chunks.jsonl.
        
        Args:
            force_reload: Force reload even if already loaded.
            
        Returns:
            List of Chunk objects.
        """
        if self._chunks is not None and not force_reload:
            return self._chunks
        
        if not self.chunks_path.exists():
            logger.warning(f"Chunks file not found: {self.chunks_path}")
            return []
        
        chunks = []
        try:
            with open(self.chunks_path, "r", encoding="utf-8") as f:
                for line in f:
                    if line.strip():
                        data = json.loads(line)
                        chunk = Chunk(
                            chunk_id=data.get("chunk_id", ""),
                            text=data.get("text", ""),
                            type=data.get("type", ""),
                            source=data.get("source", ""),
                            source_id=data.get("source_id"),
                            parent_id=data.get("parent_id"),
                            metadata=data.get("metadata", {}),
                        )
                        chunks.append(chunk)
            
            self._chunks = chunks
            logger.info(f"Loaded {len(chunks)} chunks from {self.chunks_path}")
            
        except Exception as e:
            logger.error(f"Error loading chunks: {e}")
            return []
        
        return chunks
    
    def load_faq(self, force_reload: bool = False) -> list[FAQData]:
        """Load FAQ data from faq.jsonl."""
        if self._faq is not None and not force_reload:
            return self._faq
        
        if not self.faq_path.exists():
            logger.warning(f"FAQ file not found: {self.faq_path}")
            return []
        
        faq_list = []
        try:
            with open(self.faq_path, "r", encoding="utf-8") as f:
                for line in f:
                    if line.strip():
                        data = json.loads(line)
                        content = data.get("content", {})
                        faq = FAQData(
                            id=data.get("id", ""),
                            type=data.get("type", "faq"),
                            text=data.get("text", ""),
                            question=content.get("question", ""),
                            answer=content.get("answer", ""),
                            category=content.get("category", "general"),
                            search_keywords=data.get("search_keywords", []),
                            metadata=data.get("metadata", {}),
                        )
                        faq_list.append(faq)
            
            self._faq = faq_list
            logger.info(f"Loaded {len(faq_list)} FAQ items")
            
        except Exception as e:
            logger.error(f"Error loading FAQ: {e}")
            return []
        
        return faq_list
    
    def load_menu(self, force_reload: bool = False) -> list[MenuItem]:
        """Load menu items from menu.jsonl."""
        if self._menu is not None and not force_reload:
            return self._menu
        
        if not self.menu_path.exists():
            logger.warning(f"Menu file not found: {self.menu_path}")
            return []
        
        menu_list = []
        try:
            with open(self.menu_path, "r", encoding="utf-8") as f:
                for line in f:
                    if line.strip():
                        data = json.loads(line)
                        content = data.get("content", {})
                        item = MenuItem(
                            id=data.get("id", ""),
                            name=content.get("name", ""),
                            slug=content.get("slug", ""),
                            description=content.get("description"),
                            category=content.get("category", ""),
                            price=content.get("price", 0),
                            price_formatted=content.get("price_formatted", ""),
                            size=content.get("size"),
                            spicy_level=content.get("spicy_level", 0),
                            spicy_label=content.get("spicy_label", ""),
                            ingredients=content.get("ingredients", []),
                            preparation_minutes=content.get("preparation_minutes", 10),
                            search_keywords=data.get("search_keywords", []),
                        )
                        menu_list.append(item)
            
            self._menu = menu_list
            logger.info(f"Loaded {len(menu_list)} menu items")
            
        except Exception as e:
            logger.error(f"Error loading menu: {e}")
            return []
        
        return menu_list
    
    def load_restaurant(self, force_reload: bool = False) -> Optional[RestaurantInfo]:
        """Load restaurant info."""
        if self._restaurant is not None and not force_reload:
            return self._restaurant
        
        if not self.restaurant_path.exists():
            logger.warning(f"Restaurant file not found: {self.restaurant_path}")
            return None
        
        try:
            with open(self.restaurant_path, "r", encoding="utf-8") as f:
                for line in f:
                    if line.strip():
                        data = json.loads(line)
                        content = data.get("content", {})
                        self._restaurant = RestaurantInfo(
                            brand=content.get("brand", ""),
                            name=content.get("name", ""),
                            slogan=content.get("slogan", ""),
                            description=content.get("description", ""),
                            phone=content.get("phone", ""),
                            address=content.get("address", ""),
                            open_hours=content.get("open_hours", ""),
                            max_km=content.get("max_km", 15),
                            max_guests=content.get("max_guests", 20),
                            min_advance_minutes=content.get("min_advance_minutes", 60),
                        )
                        logger.info("Loaded restaurant info")
                        break
            
        except Exception as e:
            logger.error(f"Error loading restaurant info: {e}")
        
        return self._restaurant
    
    def get_context_summary(self) -> str:
        """
        Get a summary of all knowledge base data for RAG prompt.
        
        Returns:
            Formatted context string.
        """
        context_parts = []
        
        # Restaurant info
        restaurant = self.load_restaurant()
        if restaurant:
            context_parts.append(f"""THÔNG TIN NHÀ HÀNG:
- Thương hiệu: {restaurant.brand}
- Tên: {restaurant.name}
- Slogan: {restaurant.slogan}
- Hotline: {restaurant.phone}
- Địa chỉ: {restaurant.address}
- Giờ mở cửa: {restaurant.open_hours}
- Giao hàng: tối đa {restaurant.max_km} km
- Đặt bàn: tối đa {restaurant.max_guests} khách, đặt trước {restaurant.min_advance_minutes} phút
""")
        
        # FAQ summary
        faq = self.load_faq()
        if faq:
            faq_text = "\n".join([f"- {item.question}: {item.answer}" for item in faq[:10]])
            context_parts.append(f"""CÁC CÂU HỎI THƯỜNG GẶP:
{faq_text}
(Lưu ý: Có {len(faq)} FAQ items trong cơ sở dữ liệu)
""")
        
        # Menu summary
        menu = self.load_menu()
        if menu:
            menu_summary = []
            for item in menu[:20]:
                menu_summary.append(f"- {item.name}: {item.price_formatted}")
            context_parts.append(f"""THỰC ĐƠN (một phần):
{chr(10).join(menu_summary)}
(Lưu ý: Có {len(menu)} món trong thực đơn)
""")
        
        return "\n".join(context_parts)
    
    def find_relevant_chunks(self, query: str, top_k: int = 5) -> list[Chunk]:
        """
        Find relevant chunks based on query text matching.
        
        This is a simple keyword-based matching.
        For production, use BM25 + vector search.
        
        Args:
            query: Query string (Vietnamese)
            top_k: Number of top results to return
            
        Returns:
            List of relevant chunks
        """
        chunks = self.load_chunks()
        if not chunks:
            return []
        
        query_lower = query.lower()
        query_words = set(query_lower.split())
        
        # Score chunks based on keyword matching
        scored_chunks = []
        for chunk in chunks:
            text_lower = chunk.text.lower()
            
            # Count matching words
            matches = sum(1 for word in query_words if word in text_lower)
            
            # Bonus for type-specific keywords
            if "giá" in query_lower or "bao nhiêu" in query_lower:
                if chunk.type == "menu_item":
                    matches += 2
            if "ở đâu" in query_lower or "địa chỉ" in query_lower:
                if chunk.type == "faq":
                    matches += 2
            
            if matches > 0:
                scored_chunks.append((matches, chunk))
        
        # Sort by score and return top_k
        scored_chunks.sort(key=lambda x: x[0], reverse=True)
        return [chunk for _, chunk in scored_chunks[:top_k]]


# Singleton instance
_kb_loader: Optional[KnowledgeBaseLoader] = None


def get_kb_loader() -> KnowledgeBaseLoader:
    """Get or create knowledge base loader singleton."""
    global _kb_loader
    if _kb_loader is None:
        _kb_loader = KnowledgeBaseLoader()
    return _kb_loader

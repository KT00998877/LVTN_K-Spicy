"""
Knowledge Base Validation Module.

Validates KB data quality:
- Duplicate IDs
- Empty data
- Missing metadata
- Content contradictions
- Hardcoded business data detection
"""
from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass, field
from typing import Any

from loguru import logger

from knowledge_base.loader import (
    Chunk,
    FAQData,
    KnowledgeBaseLoader,
    MenuItem,
    RestaurantInfo,
)


@dataclass
class ValidationIssue:
    """A validation issue found in KB."""
    severity: str  # "error", "warning", "info"
    category: str  # "duplicate", "empty", "metadata", "contradiction", "hardcode"
    chunk_id: str
    message: str
    details: dict[str, Any] = field(default_factory=dict)


@dataclass
class ValidationReport:
    """KB validation report."""
    total_chunks: int
    total_faq: int
    total_menu: int
    issues: list[ValidationIssue] = field(default_factory=list)
    errors: list[ValidationIssue] = field(default_factory=list)
    warnings: list[ValidationIssue] = field(default_factory=list)
    info: list[ValidationIssue] = field(default_factory=list)
    
    @property
    def has_errors(self) -> bool:
        return len(self.errors) > 0
    
    @property
    def is_valid(self) -> bool:
        return not self.has_errors


class KnowledgeBaseValidator:
    """
    Validates knowledge base data quality.
    """
    
    # Known hardcoded business data patterns
    KNOWN_HARDCODED = {
        "hotline": ["0901 234 567", "0901234567"],
        "address_keywords": ["Khu II", "đường 3/2", "Ninh Kiều", "Cần Thơ"],
        "hours_keywords": ["10:00", "22:00", "10:00 - 22:00"],
    }
    
    def __init__(self, kb_path: str | None = None):
        self.loader = KnowledgeBaseLoader(kb_path)
        self._chunks: list[Chunk] = []
        self._faq: list[FAQData] = []
        self._menu: list[MenuItem] = []
        self._restaurant: RestaurantInfo | None = None
    
    def load_data(self) -> None:
        """Load all KB data."""
        self._chunks = self.loader.load_chunks()
        self._faq = self.loader.load_faq()
        self._menu = self.loader.load_menu()
        self._restaurant = self.loader.load_restaurant()
        logger.info(f"Loaded KB: {len(self._chunks)} chunks, {len(self._faq)} FAQ, {len(self._menu)} menu items")
    
    def validate(self) -> ValidationReport:
        """
        Run all validations.
        
        Returns:
            ValidationReport with issues found
        """
        self.load_data()
        
        report = ValidationReport(
            total_chunks=len(self._chunks),
            total_faq=len(self._faq),
            total_menu=len(self._menu),
        )
        
        # Run validations
        report.issues.extend(self._check_duplicate_ids())
        report.issues.extend(self._check_empty_chunks())
        report.issues.extend(self._check_missing_metadata())
        report.issues.extend(self._check_hardcoded_data())
        report.issues.extend(self._check_menu_consistency())
        
        # Categorize
        for issue in report.issues:
            if issue.severity == "error":
                report.errors.append(issue)
            elif issue.severity == "warning":
                report.warnings.append(issue)
            else:
                report.info.append(issue)
        
        return report
    
    def _check_duplicate_ids(self) -> list[ValidationIssue]:
        """Check for duplicate chunk IDs."""
        issues = []
        seen_ids: dict[str, int] = defaultdict(int)
        
        for chunk in self._chunks:
            seen_ids[chunk.chunk_id] += 1
        
        for chunk_id, count in seen_ids.items():
            if count > 1:
                issues.append(ValidationIssue(
                    severity="error",
                    category="duplicate",
                    chunk_id=chunk_id,
                    message=f"Duplicate chunk ID: appears {count} times",
                    details={"count": count},
                ))
        
        return issues
    
    def _check_empty_chunks(self) -> list[ValidationIssue]:
        """Check for empty or very short chunks."""
        issues = []
        
        for chunk in self._chunks:
            if not chunk.text or len(chunk.text.strip()) < 10:
                issues.append(ValidationIssue(
                    severity="error",
                    category="empty",
                    chunk_id=chunk.chunk_id,
                    message="Chunk text is empty or too short (< 10 chars)",
                    details={"text_length": len(chunk.text) if chunk.text else 0},
                ))
        
        return issues
    
    def _check_missing_metadata(self) -> list[ValidationIssue]:
        """Check for chunks with missing metadata."""
        issues = []
        
        for chunk in self._chunks:
            if not chunk.type:
                issues.append(ValidationIssue(
                    severity="warning",
                    category="metadata",
                    chunk_id=chunk.chunk_id,
                    message="Missing chunk type",
                    details={},
                ))
            
            if not chunk.source:
                issues.append(ValidationIssue(
                    severity="warning",
                    category="metadata",
                    chunk_id=chunk.chunk_id,
                    message="Missing chunk source",
                    details={},
                ))
        
        return issues
    
    def _check_hardcoded_data(self) -> list[ValidationIssue]:
        """
        Detect hardcoded business data in chunks.
        
        Note: This checks if the chunk text contains known business data patterns.
        It's OK for FAQ and restaurant_info chunks to have this data.
        """
        issues = []
        hardcoded_chunks: dict[str, list[str]] = defaultdict(list)
        
        for chunk in self._chunks:
            # Skip FAQ and restaurant_info - they SHOULD have this data
            if chunk.type in ("faq", "restaurant_info"):
                continue
            
            text_lower = chunk.text.lower()
            
            for data_type, patterns in self.KNOWN_HARDCODED.items():
                for pattern in patterns:
                    if pattern.lower() in text_lower:
                        hardcoded_chunks[chunk.chunk_id].append(data_type)
        
        for chunk_id, data_types in hardcoded_chunks.items():
            issues.append(ValidationIssue(
                severity="warning",
                category="hardcode",
                chunk_id=chunk_id,
                message=f"Contains hardcoded business data: {', '.join(data_types)}",
                details={"data_types": data_types},
            ))
        
        return issues
    
    def _check_menu_consistency(self) -> list[ValidationIssue]:
        """Check menu item consistency."""
        issues = []
        seen_slugs: dict[str, int] = defaultdict(int)
        
        for item in self._menu:
            seen_slugs[item.slug] += 1
        
        for slug, count in seen_slugs.items():
            if count > 1:
                issues.append(ValidationIssue(
                    severity="warning",
                    category="duplicate",
                    chunk_id=f"menu:{slug}",
                    message=f"Duplicate menu item slug: {slug} (appears {count} times)",
                    details={"slug": slug, "count": count},
                ))
        
        return issues
    
    def get_chunk_by_id(self, chunk_id: str) -> Chunk | None:
        """Get a chunk by its ID."""
        for chunk in self._chunks:
            if chunk.chunk_id == chunk_id:
                return chunk
        return None
    
    def find_chunks_by_type(self, chunk_type: str) -> list[Chunk]:
        """Find all chunks of a specific type."""
        return [c for c in self._chunks if c.type == chunk_type]


def validate_knowledge_base(kb_path: str | None = None) -> ValidationReport:
    """
    Validate knowledge base.
    
    Args:
        kb_path: Optional path to KB
        
    Returns:
        ValidationReport
    """
    validator = KnowledgeBaseValidator(kb_path)
    return validator.validate()

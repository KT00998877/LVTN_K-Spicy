"""Smoke test: kiểm tra PhoBERT model có load được trên CPU hay không.

Phase P2 (v1.2) — chạy được trên Windows + Python 3.11+.
"""
from __future__ import annotations

import sys
import time
from pathlib import Path


def main() -> int:
    print("=" * 60)
    print("P2 Smoke Test — Load PhoBERT Base on CPU")
    print("=" * 60)

    try:
        print("\n[1/4] Import transformers + torch...")
        import torch
        from transformers import AutoModel, AutoTokenizer

        print(f"  torch:        {torch.__version__}")
        print(f"  transformers: {__import__('transformers').__version__}")
        print(f"  CUDA:         {torch.cuda.is_available()}")

        print("\n[2/4] Load tokenizer vinai/phobert-base...")
        t0 = time.time()
        tokenizer = AutoTokenizer.from_pretrained("vinai/phobert-base", use_fast=False)
        t1 = time.time()
        print(f"  Loaded in {t1 - t0:.1f}s")
        print(f"  vocab_size: {tokenizer.vocab_size}")

        print("\n[3/4] Load model vinai/phobert-base...")
        t0 = time.time()
        model = AutoModel.from_pretrained("vinai/phobert-base")
        t1 = time.time()
        print(f"  Loaded in {t1 - t0:.1f}s")
        n_params = sum(p.numel() for p in model.parameters())
        print(f"  Parameters: {n_params:,} ({n_params / 1e6:.1f}M)")

        # Test inference trên CPU.
        print("\n[4/4] Test inference (1 sample)...")
        text = "Quán có những món gì?"
        inputs = tokenizer(text, return_tensors="pt", max_length=128, truncation=True, padding=True)
        t0 = time.time()
        with torch.no_grad():
            outputs = model(**inputs)
        t1 = time.time()
        print(f"  Text: '{text}'")
        print(f"  Latency: {(t1 - t0) * 1000:.0f}ms (CPU)")
        print(f"  Output shape: {outputs.last_hidden_state.shape}")
        print(f"  Hidden dim: {outputs.last_hidden_state.shape[-1]}")

        # Memory footprint.
        import psutil
        process = psutil.Process()
        mem = process.memory_info().rss / 1024 / 1024
        print(f"\nMemory RSS: {mem:.0f} MB")

        print("\n✅ PASS — PhoBERT load được trên CPU.")
        return 0

    except ImportError as e:
        print(f"\n❌ FAIL — thiếu dependency: {e}")
        print("Chạy: pip install -r ai-service/requirements.txt")
        return 1
    except Exception as e:
        print(f"\n❌ FAIL — exception: {type(e).__name__}: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(main())

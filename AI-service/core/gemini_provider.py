"""
Gemini API provider using google-genai SDK.
"""

from __future__ import annotations

import base64
import contextlib
import contextvars
import json
import time
from typing import Any, Optional

from google import genai
from google.genai import errors as google_errors
from loguru import logger
from pydantic import BaseModel
import httpx

from core.config import settings


# Monotonic deadline for the request currently being served. It lives in a
# context variable because the provider is a shared singleton: storing the
# deadline on the instance would let concurrent chat turns overwrite each
# other's budget.
_request_deadline: contextvars.ContextVar[Optional[float]] = contextvars.ContextVar(
    "gemini_request_deadline", default=None
)


class ProviderError(Exception):
    """Base exception for Gemini provider errors."""

    def __init__(self, message: str, code: str = "PROVIDER_ERROR"):
        super().__init__(message)
        self.code = code


class ProviderNotConfiguredError(ProviderError):
    """Raised when the Gemini provider is not properly configured."""

    def __init__(self, message: str = "Provider not configured"):
        super().__init__(message, code="PROVIDER_UNAVAILABLE")


@contextlib.contextmanager
def request_deadline(seconds: float):
    """Give the current request a time budget shared by the failover chain.

    Without it every model in the chain could burn the full per-model
    timeout, so a chain of four models would need four times the deadline and
    the caller would see a timeout instead of the answer the last model could
    have produced.
    """
    token = _request_deadline.set(time.monotonic() + seconds)
    try:
        yield
    finally:
        _request_deadline.reset(token)


class GenerationResult(BaseModel):
    """Result of text generation."""
    text: str
    model: str
    latency_ms: int = 0
    input_tokens: int = 0
    output_tokens: int = 0
    finish_reason: str = ""
    error: Optional[str] = None


class StructuredResult(BaseModel):
    """Result of structured (JSON) generation."""
    data: dict
    model: str
    latency_ms: int = 0
    input_tokens: int = 0
    output_tokens: int = 0
    finish_reason: str = ""
    error: Optional[str] = None


class FunctionCallResult(BaseModel):
    """Result of function-calling generation."""
    text: str = ""
    function_calls: list[dict] = []
    model: str
    latency_ms: int = 0
    input_tokens: int = 0
    output_tokens: int = 0
    error: Optional[str] = None


def _build_function_call_part(fc: dict[str, Any]) -> dict[str, Any]:
    """
    Build one Gemini `model` part carrying a function call.

    Gemini 3.x validates the thought_signature that was issued with the call:
    replaying a function_call without it fails with 400 "Function call is missing
    a thought_signature in functionCall part". The provider reads the signature
    as raw bytes and hands it to us base64-encoded (so it survives JSON), so it
    is decoded back here. The key is omitted entirely when absent, which keeps
    the older Gemini models happy.
    """
    part: dict[str, Any] = {
        "function_call": {
            "name": fc.get("name", ""),
            "args": fc.get("args", {}),
        }
    }

    signature = fc.get("thought_signature")
    if signature:
        if isinstance(signature, str):
            try:
                signature = base64.b64decode(signature)
            except Exception:
                # Not base64 — pass it through untouched rather than corrupt it.
                part["thought_signature"] = signature
                return part
        part["thought_signature"] = signature

    return part


class GeminiProvider:
    """
    Google Gemini API provider using the official google-genai SDK.

    Features:
    - Configurable model selection
    - Configurable timeout
    - Token counting
    - Error handling with specific exceptions
    - No API key leakage in logs
    - Bounded model failover for upstream 5xx errors
    """

    # Transient errors that should be retried — they indicate spike traffic,
    # not a code or request problem. Non-transient errors (404 model, 403
    # permission, 400 bad request) are surfaced immediately.
    _RETRYABLE_EXCEPTIONS: tuple = ()

    # Status codes that mean "this model cannot serve right now" and therefore
    # justify trying the next model in the chain.
    #
    # 429 belongs here even though the SDK raises it as a ClientError: Gemini
    # free-tier quota is counted PER MODEL ("Quota exceeded for metric:
    # generate_content_free_tier_requests, limit: 20, model: <name>"), so an
    # exhausted model says nothing about the project's other models. Retrying
    # the same model would waste the customer's wait; failing over to a model
    # with quota left is what actually keeps the chatbot answering.
    #
    # 404 belongs here because Google retires model ids — the configured
    # model can disappear between releases while other ids keep working.
    _FAILOVER_STATUS_CODES: frozenset = frozenset({404, 429, 500, 502, 503, 504})

    # How long a model that reported "out of quota" is skipped.
    #
    # Gemini answers a 429 with a RetryInfo delay, so the quota is not coming
    # back within the lifetime of a chat session. Re-probing it on every
    # request would spend the customer's wait on a request that cannot
    # succeed. The breaker is per model, because quota is counted per model.
    _QUOTA_BREAKER_SECONDS = 900

    # model id -> monotonic timestamp until which the model is skipped
    _quota_blocked_until: dict[str, float] = {}

    @classmethod
    def _quota_blocked_models(cls) -> set[str]:
        now = time.monotonic()
        expired = [model for model, until in cls._quota_blocked_until.items() if until <= now]
        for model in expired:
            cls._quota_blocked_until.pop(model, None)
        return set(cls._quota_blocked_until)

    @classmethod
    def _block_model_for_quota(cls, model: str, error: Exception) -> None:
        """Remember that this model is out of quota for a while.

        The provider error carries Google's own retry delay when it knows
        one; it is used when present so the breaker is not longer than
        Google expects.
        """
        seconds = cls._QUOTA_BREAKER_SECONDS
        delay = cls._extract_retry_delay(error)
        if delay is not None:
            seconds = max(1, min(seconds, delay))
        cls._quota_blocked_until[model] = time.monotonic() + seconds
        logger.warning(
            "Gemini model {} is out of quota; skipping it for the next {}s",
            model,
            int(seconds),
        )

    @staticmethod
    def _extract_retry_delay(error: Exception) -> Optional[int]:
        """Read Google's RetryInfo delay (seconds) out of a 429 error, if any."""
        details = getattr(error, "details", None)
        if not isinstance(details, dict):
            return None
        violations = details.get("error", details)
        if not isinstance(violations, dict):
            return None
        for entry in violations.get("details", []) or []:
            if not isinstance(entry, dict):
                continue
            if "retryDelay" in entry and isinstance(entry["retryDelay"], str):
                raw = entry["retryDelay"].rstrip("s")
                try:
                    return int(float(raw))
                except ValueError:
                    continue
        return None

    # Floor for a single attempt so a slow-but-healthy model still gets a
    # realistic chance to answer instead of being cut off instantly.
    #
    # 10s is the hard floor imposed by the Gemini API ("Manually set deadline
    # 9s is too short. Minimum allowed deadline is 10s."). Setting anything
    # lower is answered with a 400 INVALID_ARGUMENT, which is a self-inflicted
    # error rather than a real timeout.
    _MIN_ATTEMPT_TIMEOUT_MS = 10000

    def __init__(
        self,
        api_key: Optional[str] = None,
        model: Optional[str] = None,
        timeout: Optional[int] = None,
        max_output_tokens: Optional[int] = None,
    ):
        self._api_key = settings.gemini_api_key if api_key is None else api_key
        self._model = model or settings.gemini_chat_model
        self._timeout = timeout or settings.gemini_timeout_seconds
        self._max_output_tokens = max_output_tokens or settings.gemini_max_output_tokens

        # Resolve retryable exceptions lazily because google_errors.* are populated
        # by the SDK at import time.
        #
        # ServerError covers 5xx (spike / temporary overload). ClientError is
        # deliberately NOT included: it also covers 400/403, which are genuine
        # request/auth problems that another model cannot fix. The 429 quota
        # case is handled separately in _should_fail_over(), which inspects
        # the status code rather than the exception class.
        if not self._RETRYABLE_EXCEPTIONS:
            candidates = []
            for name in ("ServerError", "ServiceUnavailable", "InternalServerError"):
                cls = getattr(google_errors, name, None)
                if isinstance(cls, type) and issubclass(cls, Exception):
                    candidates.append(cls)
            self._RETRYABLE_EXCEPTIONS = tuple(candidates) or (Exception,)

        if not self._api_key:
            logger.warning("Gemini API key not configured")
            self._client: Optional[Any] = None
        else:
            try:
                self._client = genai.Client(
                    api_key=self._api_key,
                    http_options=genai.types.HttpOptions(
                        timeout=self._timeout * 1000,
                        retry_options=genai.types.HttpRetryOptions(attempts=1),
                    ),
                )
                logger.info(f"Gemini provider initialized with model: {self._model}")
            except Exception as e:
                logger.error(f"Failed to initialize Gemini client: {type(e).__name__}")
                self._client = None

    @property
    def is_configured(self) -> bool:
        return self._client is not None

    @property
    def model_name(self) -> str:
        return self._model

    @property
    def model_chain(self) -> list[str]:
        """Models to try, in order, until one answers.

        The primary model comes first, then the configured fallback, then the
        built-in resilience list. Duplicates are removed while preserving the
        order so a model is never tried twice (a second 429 on the same model
        would just burn more of the customer's wait).
        """
        chain: list[str] = [self._model]
        for candidate in (settings.gemini_fallback_model, *settings.gemini_fallback_model_list):
            if isinstance(candidate, str) and candidate and candidate not in chain:
                chain.append(candidate)
        return chain

    def _should_fail_over(self, error: Exception) -> bool:
        """Decide whether the next model in the chain deserves a try.

        The SDK maps 4xx to ClientError and 5xx to ServerError, so the class
        alone cannot express "retry on quota" without also retrying 400/403.
        The status code is the reliable signal.
        """
        if isinstance(error, self._RETRYABLE_EXCEPTIONS):
            return True
        # A socket-level read timeout carries no status code. It still means
        # "this attempt did not get an answer", which is exactly the case
        # where another model can help.
        if isinstance(error, (httpx.TimeoutException, TimeoutError)):
            return True
        code = getattr(error, "code", None)
        if isinstance(code, int):
            return code in self._FAILOVER_STATUS_CODES
        status = getattr(error, "status", None)
        if isinstance(status, str):
            return status.upper() in {"RESOURCE_EXHAUSTED", "NOT_FOUND", "UNAVAILABLE", "INTERNAL"}
        return False

    def _retry_generate_content(self, contents, config):
        """Try each model in the chain until one answers.

        Fails over on quota (429), retired model ids (404) and upstream
        overload (5xx). A 400/403 aborts the chain immediately: no other
        model can fix a malformed request or a missing permission.

        Each attempt gets its own timeout budget, derived from the remaining
        time in the overall chat deadline. Without it, a chain of N models
        could each burn the full per-model timeout and blow the caller's
        deadline — the customer would see a timeout instead of the answer the
        last model in the chain could have produced.
        """
        payload = [contents] if isinstance(contents, str) else contents
        all_models = self.model_chain
        blocked = self._quota_blocked_models()
        models = [model for model in all_models if model not in blocked]
        if not models:
            # Every model is known to be out of quota. Try them all again
            # rather than giving up: the breaker may be stale.
            models = all_models
        elif len(models) < len(all_models):
            logger.info(
                "Skipping quota-blocked models {}; using {}",
                sorted(blocked & set(all_models)),
                models,
            )
        # Only try as many models as the remaining budget can cover. Each
        # attempt needs at least the Gemini minimum deadline, so attempting
        # more would push the request past the caller's deadline.
        affordable = self._affordable_attempts(len(models))
        if affordable < len(models):
            logger.info(
                "Budget allows {} of {} models; trying the first {}",
                affordable, len(models), affordable,
            )
            models = models[:affordable]
        last_error: Optional[Exception] = None
        for index, model in enumerate(models):
            attempt_config = self._with_attempt_timeout(config, index, len(models))
            try:
                return self._client.models.generate_content(model=model, contents=payload, config=attempt_config), model
            except Exception as error:
                last_error = error
                if getattr(error, "code", None) == 429:
                    self._block_model_for_quota(model, error)
                if not self._should_fail_over(error) or index == len(models) - 1:
                    raise
                logger.warning(
                    "Gemini model {} failed ({}); trying next model {}",
                    model,
                    getattr(error, "code", type(error).__name__),
                    models[index + 1],
                )
        raise last_error  # pragma: no cover - loop always returns or raises

    def _with_attempt_timeout(self, config, index: int, total: int):
        """Cap one attempt so the whole chain stays inside the chat deadline."""
        budget_ms = self._attempt_timeout_ms(index, total)
        if budget_ms is None:
            return config
        try:
            return config.model_copy(update={"http_options": genai.types.HttpOptions(timeout=budget_ms)})
        except Exception:  # pragma: no cover - defensive, config shape may vary
            return config

    def _attempt_timeout_ms(self, index: int, total: int) -> Optional[int]:
        """Timeout for the attempt at `index`, in milliseconds.

        The attempt gets an equal share of the time that is actually left, so
        the whole chain fits inside the caller's deadline. Each share is at
        least the Gemini minimum deadline (10s) and at most the configured
        per-model timeout.
        """
        remaining = self._remaining_budget_ms()
        if remaining is None:
            return None
        floor = self._MIN_ATTEMPT_TIMEOUT_MS
        attempts_left = max(1, total - index)
        share = remaining // attempts_left
        return max(floor, min(self._timeout * 1000, share))

    def _affordable_attempts(self, total: int) -> int:
        """How many models the remaining budget can actually afford.

        Every attempt needs at least the Gemini minimum deadline, so a budget
        smaller than floor * total cannot cover the whole chain. Attempting
        the models that do not fit would push the request past the caller's
        deadline and turn a working answer into a timeout.
        """
        remaining = self._remaining_budget_ms()
        if remaining is None:
            return total
        return max(1, min(total, remaining // self._MIN_ATTEMPT_TIMEOUT_MS))

    def _remaining_budget_ms(self) -> Optional[int]:
        """Milliseconds left in the overall chat deadline, if one is running."""
        deadline = _request_deadline.get()
        if deadline is None:
            return None
        return max(0, int((deadline - time.monotonic()) * 1000))

    def generate(
        self,
        prompt: str,
        system_instruction: Optional[str] = None,
        temperature: float = 0.7,
        max_tokens: Optional[int] = None,
        history: Optional[list[dict[str, Any]]] = None,
    ) -> GenerationResult:
        """
        Generate plain-text response from prompt.

        Args:
            prompt: Current user message
            system_instruction: System prompt
            temperature: Sampling temperature
            max_tokens: Max output tokens
            history: Conversation history as [{"role": "user"|"assistant", "content": str}]
        """
        if not self.is_configured:
            return GenerationResult(text="", model=self._model, latency_ms=0, error="Gemini API key not configured")

        start_time = time.perf_counter()
        try:
            response, used_model = self._retry_generate_content(
                contents=self._build_contents(prompt, history),
                config=genai.types.GenerateContentConfig(
                    temperature=temperature,
                    max_output_tokens=max_tokens or self._max_output_tokens,
                    system_instruction=system_instruction,
                ),
            )
            latency_ms = int((time.perf_counter() - start_time) * 1000)

            text = ""
            input_tokens = 0
            output_tokens = 0
            finish_reason = ""

            if response.candidates:
                candidate = response.candidates[0]
                if candidate.content and candidate.content.parts:
                    text = "".join(part.text or "" for part in candidate.content.parts)
                    finish_reason = str(candidate.finish_reason) if candidate.finish_reason else ""
            if hasattr(response, 'usage_metadata') and response.usage_metadata:
                usage = response.usage_metadata
                input_tokens = getattr(usage, 'prompt_token_count', 0) or 0
                output_tokens = getattr(usage, 'candidates_token_count', 0) or 0

            return GenerationResult(
                text=text,
                model=used_model,
                latency_ms=latency_ms,
                input_tokens=input_tokens,
                output_tokens=output_tokens,
                finish_reason=finish_reason,
            )
        except google_errors.APIError as e:
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            error_msg = self._map_google_error(e)
            logger.warning(f"Gemini API error: {error_msg}")
            return GenerationResult(text="", model=self._model, latency_ms=latency_ms, error=error_msg)
        except Exception as e:
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            logger.error(f"Unexpected error in Gemini generate: {type(e).__name__}")
            return GenerationResult(text="", model=self._model, latency_ms=latency_ms, error=f"Unexpected error: {type(e).__name__}")

    @staticmethod
    def _build_contents(
        prompt: str,
        history: Optional[list[dict[str, Any]]] = None,
    ) -> list[dict[str, Any]]:
        """
        Build Gemini `contents` payload with optional conversation history.

        History turns are mapped to Gemini's user/model roles so the model
        keeps the thread context (e.g. "Giá bao nhiêu?" after "Cho tôi 2 mì cay bò").
        """
        contents: list[dict[str, Any]] = []

        for turn in (history or []):
            role = turn.get("role", "user")
            content = (turn.get("content") or "").strip()
            if not content:
                continue
            contents.append({
                "role": "model" if role == "assistant" else "user",
                "parts": [{"text": content}],
            })

        if prompt:
            contents.append({"role": "user", "parts": [{"text": prompt}]})

        return contents

    def generate_structured(
        self,
        prompt: str,
        response_schema: Any,
        system_instruction: Optional[str] = None,
        temperature: float = 0.7,
    ) -> StructuredResult:
        """Generate a JSON response constrained to the given schema."""
        if not self.is_configured:
            return StructuredResult(data={}, model=self._model, latency_ms=0, error="Gemini API key not configured")

        start_time = time.perf_counter()
        try:
            contents = [prompt]
            response, used_model = self._retry_generate_content(
                contents=contents,
                config=genai.types.GenerateContentConfig(
                    temperature=temperature,
                    max_output_tokens=self._max_output_tokens,
                    system_instruction=system_instruction,
                    response_mime_type="application/json",
                    response_schema=response_schema,
                ),
            )
            latency_ms = int((time.perf_counter() - start_time) * 1000)

            text = ""
            input_tokens = 0
            output_tokens = 0
            finish_reason = ""

            if response.candidates:
                candidate = response.candidates[0]
                if candidate.content and candidate.content.parts:
                    text = "".join(part.text or "" for part in candidate.content.parts)
            if hasattr(response, 'usage_metadata') and response.usage_metadata:
                usage = response.usage_metadata
                input_tokens = getattr(usage, 'prompt_token_count', 0) or 0
                output_tokens = getattr(usage, 'candidates_token_count', 0) or 0

            try:
                data = json.loads(text) if text else {}
            except json.JSONDecodeError:
                logger.warning("Failed to parse JSON response")
                return StructuredResult(
                    data={},
                    model=self._model,
                    latency_ms=latency_ms,
                    input_tokens=input_tokens,
                    output_tokens=output_tokens,
                    finish_reason=finish_reason,
                    error="Invalid JSON response",
                )

            return StructuredResult(
                data=data,
                model=used_model,
                latency_ms=latency_ms,
                input_tokens=input_tokens,
                output_tokens=output_tokens,
                finish_reason=finish_reason,
            )
        except google_errors.APIError as e:
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            error_msg = self._map_google_error(e)
            logger.warning(f"Gemini API error in structured generation: {error_msg}")
            return StructuredResult(data={}, model=self._model, latency_ms=latency_ms, error=error_msg)
        except Exception as e:
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            logger.error(f"Unexpected error in Gemini structured: {type(e).__name__}")
            return StructuredResult(data={}, model=self._model, latency_ms=latency_ms, error=f"Unexpected error: {type(e).__name__}")

    def _map_google_error(self, error: Exception) -> str:
        """Map Google API errors to user-friendly messages with raw details appended."""
        error_str = str(error)
        s = error_str.lower()
        # The status code wins over keyword sniffing. "INVALID_ARGUMENT" contains
        # the substring "invalid", so the API-key branch used to swallow every
        # 400 (bad function-call ordering, malformed schema) and misreport it as
        # "Invalid API key", hiding the real defect from the logs.
        if "400" in error_str or "INVALID_ARGUMENT" in error_str:
            return f"Bad request / invalid argument: {error_str[:120]}"
        if "api_key" in s:
            return f"Invalid API key: {error_str[:120]}"
        if "429" in error_str or "RESOURCE_EXHAUSTED" in error_str or "quota" in s:
            return f"Rate limit / quota: {error_str[:120]}"
        if "timeout" in s or "timed out" in s:
            return f"Timeout: {error_str[:120]}"
        if "503" in error_str or "UNAVAILABLE" in error_str:
            return f"Upstream unavailable (likely overloaded): {error_str[:120]}"
        if "403" in error_str or "PERMISSION_DENIED" in error_str:
            return f"Permission denied: {error_str[:120]}"
        if "404" in error_str or "NOT_FOUND" in error_str or "not found" in s:
            return f"Model not found: {error_str[:120]}"
        if "blocked" in s or "safety" in s:
            return f"Blocked by safety filter: {error_str[:120]}"
        return f"Gemini error ({type(error).__name__}): {error_str[:200]}"

    def generate_with_functions(
        self,
        prompt: str,
        tools: list[dict[str, Any]],
        system_instruction: Optional[str] = None,
        temperature: float = 0.7,
        history: Optional[list[dict[str, Any]]] = None,
        function_responses: Optional[list[dict[str, Any]]] = None,
        prior_function_calls: Optional[list[dict[str, Any]]] = None,
        tool_history: Optional[list[dict[str, Any]]] = None,
        function_calling_mode: str = "AUTO",
    ) -> FunctionCallResult:
        """
        Generate response with function-calling capability.

        Args:
            prompt: Current user message
            tools: Gemini function declarations
            system_instruction: System prompt
            temperature: Sampling temperature
            history: Conversation history as [{"role": "user"|"assistant", "content": str}]
            function_responses: Results of previously executed tools as
                [{"name": str, "response": dict}] — used for multi-turn tool loops.
            prior_function_calls: The function calls the model made in the
                previous turn, as [{"name": str, "args": dict}]. Required when
                sending function_responses: Gemini requires the function_call
                turn to be present immediately before the function_response
                turn, otherwise it answers 400 INVALID_ARGUMENT.
        """
        if not self.is_configured:
            return FunctionCallResult(text="", function_calls=[], model=self._model, latency_ms=0, error="Gemini API key not configured")

        start_time = time.perf_counter()
        try:
            contents = self._build_function_contents(
                prompt,
                history,
                function_responses,
                prior_function_calls,
            )
            if tool_history:
                contents.extend(tool_history)
            function_declarations = []
            for tool in tools:
                function_declarations.append(tool)

            tools_config = genai.types.Tool(function_declarations=function_declarations)
            response, used_model = self._retry_generate_content(
                contents=contents,
                config=genai.types.GenerateContentConfig(
                    temperature=temperature,
                    max_output_tokens=self._max_output_tokens,
                    system_instruction=system_instruction,
                    tools=[tools_config],
                    tool_config=genai.types.ToolConfig(
                        function_calling_config=genai.types.FunctionCallingConfig(mode=function_calling_mode),
                    ),
                ),
            )
            latency_ms = int((time.perf_counter() - start_time) * 1000)

            text = ""
            function_calls = []
            input_tokens = 0
            output_tokens = 0

            if response.candidates:
                candidate = response.candidates[0]
                if candidate.content and candidate.content.parts:
                    for part in candidate.content.parts:
                        if hasattr(part, 'text') and part.text:
                            text += part.text
                        if hasattr(part, 'function_call') and part.function_call:
                            # Gemini 3.x rejects a replayed function_call that
                            # drops the thought_signature the model emitted with
                            # it ("Function call is missing a thought_signature"),
                            # so the signature must travel back with the args.
                            thought_signature = getattr(part, "thought_signature", None)
                            function_calls.append({
                                "name": part.function_call.name,
                                "args": dict(part.function_call.args) if part.function_call.args else {},
                                "thought_signature": (
                                    base64.b64encode(thought_signature).decode()
                                    if isinstance(thought_signature, (bytes, bytearray))
                                    else thought_signature
                                ),
                            })

            if hasattr(response, 'usage_metadata') and response.usage_metadata:
                usage = response.usage_metadata
                input_tokens = getattr(usage, 'prompt_token_count', 0) or 0
                output_tokens = getattr(usage, 'candidates_token_count', 0) or 0

            return FunctionCallResult(
                text=text,
                function_calls=function_calls,
                model=used_model,
                latency_ms=latency_ms,
                input_tokens=input_tokens,
                output_tokens=output_tokens,
            )
        except google_errors.APIError as e:
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            error_msg = self._map_google_error(e)
            logger.warning(f"Gemini API error in function calling: {error_msg}")
            return FunctionCallResult(text="", function_calls=[], model=self._model, latency_ms=latency_ms, error=error_msg)
        except Exception as e:
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            logger.error(f"Unexpected error in Gemini function calling: {type(e).__name__}")
            return FunctionCallResult(text="", function_calls=[], model=self._model, latency_ms=latency_ms, error=f"Unexpected error: {type(e).__name__}")

    @staticmethod
    def _build_function_contents(
        prompt: str,
        history: Optional[list[dict[str, Any]]] = None,
        function_responses: Optional[list[dict[str, Any]]] = None,
        prior_function_calls: Optional[list[dict[str, Any]]] = None,
    ) -> list[dict[str, Any]]:
        """
        Build Gemini `contents` payload for a (multi-turn) function-calling turn.

        Shape:
            [ ...history turns...,
              {"role": "user", "parts": [{"text": prompt}]},
              {"role": "model", "parts": [{"function_call": {...}}]},
              {"role": "user", "parts": [{"function_response": {...}}]}, ... ]

        The function_response parts MUST come after the model turn that
        requested them, and MUST NOT be merged with the text prompt — Gemini
        rejects a mixed user turn. When `prior_function_calls` is given, the
        matching model turn is emitted so the function_response turn directly
        follows the function_call it answers.
        """
        contents: list[dict[str, Any]] = []

        for turn in (history or []):
            role = turn.get("role", "user")
            content = (turn.get("content") or "").strip()
            if not content:
                continue
            contents.append({
                "role": "model" if role == "assistant" else "user",
                "parts": [{"text": content}],
            })

        if prompt:
            contents.append({"role": "user", "parts": [{"text": prompt}]})

        if prior_function_calls:
            contents.append({
                "role": "model",
                "parts": [_build_function_call_part(fc) for fc in prior_function_calls],
            })

        if function_responses:
            contents.append({
                "role": "user",
                "parts": [{
                    "function_response": {
                        "name": fr.get("name", ""),
                        "response": fr.get("response", {}),
                    }
                } for fr in function_responses],
            })

        return contents

def get_gemini_provider() -> "GeminiProvider":
    """Module-level factory used by other modules to obtain a singleton provider."""
    return GeminiProvider()

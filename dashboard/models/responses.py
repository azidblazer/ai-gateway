"""Pydantic response models for usage and feedback APIs."""
from typing import Literal, Optional

from pydantic import BaseModel, Field, model_validator


class ModelSpend(BaseModel):
    """Spend data for a single AI model."""
    model: str
    spend: float = Field(ge=0, description="Cost in USD (not displayed to users)")
    tokens: int = Field(ge=0, description="Total tokens used")
    percentage: float = Field(ge=0, le=100, description="Percentage of weekly limit")


class FeedbackRequest(BaseModel):
    """User feedback submission. All fields optional; at least one required."""
    time_savings: Optional[Literal[
        "~5 minutes", "~15 minutes", "30+ minutes", "No"
    ]] = None
    use_case: Optional[Literal[
        "Draft content", "Summarize information",
        "Research / brainstorming", "Technical help", "Other"
    ]] = None
    use_case_other: Optional[str] = Field(default=None, max_length=500)
    success_rating: Optional[Literal[
        "Fully met my needs", "Partially met my needs", "Did not meet my needs"
    ]] = None
    issues: Optional[Literal[
        "No issues", "Hit usage limit", "File upload problem",
        "Confusing results", "Other"
    ]] = None
    issues_other: Optional[str] = Field(default=None, max_length=500)
    message: Optional[str] = Field(default=None, max_length=5000)

    @model_validator(mode="after")
    def _require_at_least_one(self):
        if not any([
            self.time_savings, self.use_case, self.success_rating,
            self.issues, (self.message or "").strip(),
        ]):
            raise ValueError("Please answer at least one question or leave a comment.")
        return self


class FeedbackResponse(BaseModel):
    """Feedback submission result."""
    success: bool
    detail: str


class UsageResponse(BaseModel):
    """Weekly usage data response."""
    period_start: str = Field(description="Start of billing period (e.g., 'Jan 17')")
    period_end: str = Field(description="End of billing period (e.g., 'Jan 24, 2025')")
    total_spend: float = Field(ge=0, description="Total spend in USD")
    percentage_used: float = Field(ge=0, le=100, description="Percentage of weekly limit used")
    models: list[ModelSpend] = Field(description="Breakdown by model")


class CoachingTip(BaseModel):
    """A single coaching tip from the AI analysis."""
    title: str
    detail: str
    category: str  # FILES, CONTEXT, MODEL, GENERAL
    estimated_savings: str | None = None


class CoachingStats(BaseModel):
    """Summary statistics shown alongside coaching tips."""
    total_requests: int = 0
    total_chats: int = 0
    avg_messages_per_chat: float = 0.0
    longest_chat_messages: int = 0
    total_file_uploads: int = 0
    unique_files: int = 0


class CoachingResponse(BaseModel):
    """AI coaching response with tips and stats."""
    period_start: str
    period_end: str
    summary: str | None = None
    tips: list[CoachingTip] = []
    stats: CoachingStats
    status: str  # "ready", "unavailable"
    cached: bool = False
    generated_at: str | None = None

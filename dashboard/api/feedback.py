"""Feedback email endpoint."""
import html
from email.message import EmailMessage
from typing import Annotated

import aiosmtplib
from fastapi import APIRouter, Depends, HTTPException, Request, status

from auth.models import CurrentUser
from auth.token_validator import get_current_user
from config import settings
from models.responses import FeedbackRequest, FeedbackResponse

router = APIRouter(prefix="/api", tags=["feedback"])

_RATE_LIMIT_SECONDS = 60

# Atomically claim a submission slot: insert or bump last_submit, but only if the
# previous submission is old enough. Returns a row iff the claim succeeded — shared
# across all workers via the DB (an in-memory dict would be per-worker).
_CLAIM_SQL = """
INSERT INTO dashboard_feedback_ratelimit (email, last_submit)
VALUES ($1, NOW())
ON CONFLICT (email) DO UPDATE SET last_submit = NOW()
WHERE dashboard_feedback_ratelimit.last_submit <= NOW() - make_interval(secs => $2)
RETURNING 1
"""

_REMAINING_SQL = """
SELECT CEIL(EXTRACT(EPOCH FROM (last_submit + make_interval(secs => $2) - NOW())))
FROM dashboard_feedback_ratelimit WHERE email = $1
"""

# Release the slot (used when the send fails, so the user can retry immediately)
_RELEASE_SQL = "DELETE FROM dashboard_feedback_ratelimit WHERE email = $1"


@router.post(
    "/feedback",
    response_model=FeedbackResponse,
    responses={
        401: {"description": "Invalid or expired token"},
        429: {"description": "Rate limited"},
        503: {"description": "SMTP not configured or send failed"},
    },
)
async def submit_feedback(
    request: Request,
    body: FeedbackRequest,
    current_user: Annotated[CurrentUser, Depends(get_current_user)],
) -> FeedbackResponse:
    """Send user feedback via email. Requires valid Open WebUI Bearer token."""
    # Check SMTP is configured
    if not settings.SMTP_HOST or not settings.FEEDBACK_RECIPIENTS:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Feedback is not configured yet. Please try again later.",
        )

    # Rate limit (DB-backed, shared across workers)
    pool = request.app.state.pool
    async with pool.acquire() as conn:
        claimed = await conn.fetchrow(
            _CLAIM_SQL, current_user.email, _RATE_LIMIT_SECONDS
        )
        if claimed is None:
            remaining = await conn.fetchval(
                _REMAINING_SQL, current_user.email, _RATE_LIMIT_SECONDS
            )
            raise HTTPException(
                status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                detail=f"Please wait {int(remaining or _RATE_LIMIT_SECONDS)} seconds before submitting again.",
            )

    # Build email
    recipients = [
        r.strip() for r in settings.FEEDBACK_RECIPIENTS.split(";") if r.strip()
    ]
    display_name = html.escape(current_user.name or "Unknown")
    user_email = html.escape(current_user.email)
    # Collect answered structured fields as (label, value) rows, skipping blanks.
    rows: list[tuple[str, str]] = []
    if body.time_savings:
        rows.append(("Time saved", body.time_savings))
    if body.use_case:
        uc = body.use_case
        if body.use_case == "Other" and body.use_case_other:
            uc = f"{uc} — {body.use_case_other}"
        rows.append(("Use case", uc))
    if body.success_rating:
        rows.append(("Met needs", body.success_rating))
    if body.issues:
        iss = body.issues
        if body.issues == "Other" and body.issues_other:
            iss = f"{iss} — {body.issues_other}"
        rows.append(("Issues", iss))

    has_message = bool(body.message and body.message.strip())
    safe_message = html.escape(body.message) if has_message else ""

    # Plaintext body
    text_lines = [
        f"Feedback from {current_user.name or 'Unknown'} ({current_user.email})",
        "",
    ]
    for label, value in rows:
        text_lines.append(f"{label}: {value}")
    if has_message:
        if rows:
            text_lines.append("")
        text_lines.append(body.message)
    text_body = "\n".join(text_lines)

    # HTML body — structured rows in the table, comment in the gray block (if any)
    meta_rows = "".join(
        f'<tr><td style="padding:4px 12px 4px 0;color:#666">{html.escape(label)}</td>'
        f'<td style="padding:4px 0">{html.escape(value)}</td></tr>'
        for label, value in rows
    )
    comment_block = (
        f'<div style="background:#f8f9fa;padding:16px;border-radius:8px;'
        f'white-space:pre-wrap">{safe_message}</div>'
        if has_message
        else ""
    )
    html_body = f"""\
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:560px">
  <h2 style="color:#2563EB;margin:0 0 16px">AI Gateway Feedback</h2>
  <table style="margin-bottom:16px;border-collapse:collapse">
    <tr><td style="padding:4px 12px 4px 0;color:#666">From</td>
        <td style="padding:4px 0"><strong>{display_name}</strong></td></tr>
    <tr><td style="padding:4px 12px 4px 0;color:#666">Email</td>
        <td style="padding:4px 0">{user_email}</td></tr>
    {meta_rows}
  </table>
  {comment_block}
</div>"""

    msg = EmailMessage()
    msg["Subject"] = "AI Gateway Feedback"
    msg["From"] = settings.FEEDBACK_FROM_EMAIL
    msg["To"] = ", ".join(recipients)
    msg["Reply-To"] = current_user.email
    msg.set_content(text_body)
    msg.add_alternative(html_body, subtype="html")

    # Send
    try:
        kwargs: dict = {
            "hostname": settings.SMTP_HOST,
            "port": settings.SMTP_PORT,
        }
        if settings.SMTP_USE_TLS:
            kwargs["use_tls"] = True
        else:
            kwargs["start_tls"] = False
        if settings.SMTP_USERNAME:
            kwargs["username"] = settings.SMTP_USERNAME
            kwargs["password"] = settings.SMTP_PASSWORD

        await aiosmtplib.send(msg, **kwargs)
    except Exception as e:
        print(f"SMTP send error for {current_user.email}: {e}")
        # Release the rate-limit slot so a failed send doesn't cost the user a minute
        try:
            async with pool.acquire() as conn:
                await conn.execute(_RELEASE_SQL, current_user.email)
        except Exception:
            pass
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Failed to send feedback. Please try again later.",
        )

    print(f"Feedback sent from {current_user.email}")

    return FeedbackResponse(success=True, detail="Feedback sent. Thank you!")

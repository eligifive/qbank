#!/usr/bin/env python3
"""
pdf_to_qbank.py
================
Reads a previous-year-question-paper PDF, sends it to Gemini for extraction +
classification (subject / subtopic / applicable exams / correct answer /
explanation), and writes a ready-to-run `qbank` INSERT SQL file that matches
your `qbank` table schema exactly.

--------------------------------------------------------------------------
HOW IT WORKS
--------------------------------------------------------------------------
1. It parses your existing phpMyAdmin dump files for `subjects`,
   `subtopics`, `entrance_exams` and `exam_subjects` so it knows every valid
   subject_id, subtopic_id and exam_code that exists in your database.
2. It sends the PDF (as a native file part, Gemini reads PDFs directly -
   no OCR/text-extraction library needed) together with that reference data
   to the Gemini API, and asks it to return STRICT JSON (using a JSON
   response schema) containing every question it found, each one already
   classified against your real subject_id / subtopic_id / exam_code values.
3. It validates every answer against the reference data (wrong/hallucinated
   ids are corrected or flagged rather than silently inserted).
4. It writes a `.sql` file with `INSERT INTO qbank (...) VALUES (...)`
   statements you can import directly (question_id and content_hash are
   generated automatically; created_at/updated_at use CURRENT_TIMESTAMP).

--------------------------------------------------------------------------
SETUP
--------------------------------------------------------------------------
    pip install requests pypdf

--------------------------------------------------------------------------
USAGE
--------------------------------------------------------------------------
    python pdf_to_qbank.py \
        --pdf "NEET_2024_PYQ.pdf" \
        --api-key "YOUR_GEMINI_API_KEY" \
        --model gemini-3.7-flash \
        --exam-hint "NEET_UG" \
        --subjects-sql subjects.sql \
        --subtopics-sql subtopics.sql \
        --entrance-exams-sql entrance_exams.sql \
        --exam-subjects-sql exam_subjects.sql \
        --output neet_2024_qbank.sql

If you omit --api-key, the script reads the GEMINI_API_KEY environment
variable, or prompts you securely at runtime.

--exam-hint is optional but strongly recommended -- tell it which exam
this paper is from (use the exam_code from entrance_exams, e.g. NEET_UG,
JEE_MAIN, CAT, CLAT_UG ...). Gemini will still try to infer applicable
exams on its own (a question can be tagged for more than one exam), but
the hint anchors it to the paper's real source.

If the PDF is large, it's automatically split into page-batches (see
--pages-per-batch, default 12) so Gemini's output never gets truncated
(and so a big/well-known paper is far less likely to trip Gemini's
RECITATION filter, since each request only ever asks for a small slice).
Results from every batch are merged into a single SQL file.

--ref-json lets a caller (e.g. the qbank PHP admin panel) pass live
subjects/subtopics/exams as one JSON file instead of the four --*-sql
dumps above.

--groq-api-key / --groq-model: if set, any batch where Gemini fails
(network error, quota, or a blocked/RECITATION response) is automatically
retried against Groq instead, using text extracted from that batch's page
range.
"""

import argparse
import base64
import builtins
import getpass
import hashlib
import json
import os
import re
import sys
import time
import uuid

import requests

try:
    from pypdf import PdfReader, PdfWriter
except ImportError:
    print("Missing dependency. Run: pip install pypdf", file=sys.stderr)
    sys.exit(1)

# Force UTF-8 on stdout/stderr regardless of the host OS's console code page.
# This is what caused UnicodeEncodeError crashes on Windows: when this
# script's output is piped to another process (like PHP's proc_open) instead
# of a real terminal, Python falls back to the OS's ANSI code page (often
# cp1252 on Windows) -- which can't represent many Greek/math/Unicode
# characters that legitimately show up in question text -- and raises instead
# of just printing. errors="replace" means a log line can, at worst, show a
# "?" for a stray character; it can no longer crash the whole run.
for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

# Always flush stdout/stderr immediately -- important when this script's
# output is being streamed live by another process (e.g. the qbank PHP
# admin panel) instead of a real terminal, where stdout would otherwise be
# block-buffered and the live log would appear to "freeze" until the end.
_print = builtins.print


def print(*args, **kwargs):  # noqa: A001 - intentional shadow, see above
    kwargs.setdefault("flush", True)
    _print(*args, **kwargs)


def emit_result(rows):
    """Writes the final QBANK_JSON_RESULT: payload -- the only output line
    that actually carries data (everything else is just a human-readable
    log line) -- straight to the raw stdout byte stream as UTF-8. This
    sidesteps console/codepage encoding entirely, so it can never crash and
    never silently mangles a student-facing character the way errors="replace"
    on a log line safely can."""
    line = "QBANK_JSON_RESULT:" + json.dumps(rows, ensure_ascii=False) + "\n"
    try:
        sys.stdout.buffer.write(line.encode("utf-8"))
        sys.stdout.buffer.flush()
    except Exception:
        # Last-resort fallback: \uXXXX-escape everything non-ASCII. This is
        # always encodable in any codec and PHP's json_decode() reads
        # \uXXXX escapes natively, so no data is lost either way.
        _print("QBANK_JSON_RESULT:" + json.dumps(rows, ensure_ascii=True))


GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
GROQ_ENDPOINT = "https://api.groq.com/openai/v1/chat/completions"

# --------------------------------------------------------------------------
# Reference data loading (parses your phpMyAdmin .sql dumps directly, so it
# always reflects the real, current contents of your tables)
# --------------------------------------------------------------------------

INSERT_RE = re.compile(
    r"INSERT INTO\s+`?(\w+)`?\s*\([^)]*\)\s*VALUES\s*(.+?);",
    re.IGNORECASE | re.DOTALL,
)


def _split_sql_tuples(values_blob):
    """Split a VALUES (...),(...),(...) blob into individual tuple strings,
    respecting quoted strings and escaped quotes."""
    tuples = []
    depth = 0
    current = []
    in_string = False
    quote_char = ""
    i = 0
    n = len(values_blob)
    while i < n:
        ch = values_blob[i]
        if in_string:
            if ch == "\\" and i + 1 < n:
                current.append(ch)
                current.append(values_blob[i + 1])
                i += 2
                continue
            if ch == quote_char:
                in_string = False
            current.append(ch)
        else:
            if ch in ("'", '"'):
                in_string = True
                quote_char = ch
                current.append(ch)
            elif ch == "(":
                depth += 1
                if depth == 1:
                    current = []
                    i += 1
                    continue
                current.append(ch)
            elif ch == ")":
                depth -= 1
                if depth == 0:
                    tuples.append("".join(current))
                    i += 1
                    continue
                current.append(ch)
            else:
                if depth > 0:
                    current.append(ch)
        i += 1
    return tuples


def _split_fields(tuple_str):
    """Split one row-tuple string on top-level commas (outside quotes)."""
    fields = []
    current = []
    in_string = False
    quote_char = ""
    i = 0
    n = len(tuple_str)
    while i < n:
        ch = tuple_str[i]
        if in_string:
            if ch == "\\" and i + 1 < n:
                current.append(ch)
                current.append(tuple_str[i + 1])
                i += 2
                continue
            if ch == quote_char:
                in_string = False
                current.append(ch)
                i += 1
                continue
            current.append(ch)
        else:
            if ch in ("'", '"'):
                in_string = True
                quote_char = ch
                current.append(ch)
            elif ch == ",":
                fields.append("".join(current).strip())
                current = []
            else:
                current.append(ch)
        i += 1
    if current:
        fields.append("".join(current).strip())
    return fields


def _unquote(field):
    field = field.strip()
    if len(field) >= 2 and field[0] == field[-1] and field[0] in ("'", '"'):
        inner = field[1:-1]
        inner = inner.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\")
        return inner
    if field.upper() == "NULL":
        return None
    return field


def parse_sql_dump_rows(path, table_name):
    """Extract every row inserted into `table_name` from a phpMyAdmin dump."""
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        content = f.read()

    rows = []
    for match in INSERT_RE.finditer(content):
        found_table = match.group(1)
        if found_table.lower() != table_name.lower():
            continue
        values_blob = match.group(2)
        for tup in _split_sql_tuples(values_blob):
            fields = [_unquote(f) for f in _split_fields(tup)]
            rows.append(fields)
    return rows


def load_reference_data(subjects_sql, subtopics_sql, entrance_exams_sql, exam_subjects_sql):
    subjects = {}
    for row in parse_sql_dump_rows(subjects_sql, "subjects"):
        # subject_id, subject_name, description, is_active, created_at, updated_at
        subject_id = int(row[0])
        subjects[subject_id] = row[1]

    subtopics = {}
    subtopics_by_subject = {}
    for row in parse_sql_dump_rows(subtopics_sql, "subtopics"):
        # subtopic_id, subject_id, subtopic_name, description, is_active, ...
        subtopic_id = int(row[0])
        subject_id = int(row[1])
        name = row[2]
        subtopics[subtopic_id] = (subject_id, name)
        subtopics_by_subject.setdefault(subject_id, []).append((subtopic_id, name))

    exams = {}
    exam_id_to_code = {}
    for row in parse_sql_dump_rows(entrance_exams_sql, "entrance_exams"):
        # exam_id, exam_code, display_name, exam_name, exam_level, exam_category, eligibility
        exam_id = int(row[0])
        exam_code = row[1]
        exams[exam_code] = {
            "exam_id": exam_id,
            "display_name": row[2],
            "exam_name": row[3],
            "exam_level": row[4],
            "exam_category": row[5],
        }
        exam_id_to_code[exam_id] = exam_code

    exam_subjects = {}  # exam_code -> [subject_id, ...]
    for row in parse_sql_dump_rows(exam_subjects_sql, "exam_subjects"):
        # exam_subject_id, exam_id, subject_id, created_at
        exam_id = int(row[1])
        subject_id = int(row[2])
        code = exam_id_to_code.get(exam_id)
        if code:
            exam_subjects.setdefault(code, []).append(subject_id)

    return {
        "subjects": subjects,                        # {subject_id: name}
        "subtopics": subtopics,                       # {subtopic_id: (subject_id, name)}
        "subtopics_by_subject": subtopics_by_subject,  # {subject_id: [(subtopic_id, name), ...]}
        "exams": exams,                               # {exam_code: {...}}
        "exam_subjects": exam_subjects,                # {exam_code: [subject_id, ...]}
    }


def load_reference_data_from_json(path):
    """Load reference data from a JSON file instead of SQL dumps -- used
    when this script is invoked by the qbank PHP admin panel, which writes
    the *live* subjects/subtopics/exams straight from the database to a
    temp JSON file before running this script, instead of relying on
    possibly-stale phpMyAdmin dump files."""
    with open(path, "r", encoding="utf-8") as f:
        raw = json.load(f)

    subjects = {int(k): v for k, v in raw.get("subjects", {}).items()}

    subtopics = {}
    subtopics_by_subject = {}
    for k, v in raw.get("subtopics", {}).items():
        subtopic_id = int(k)
        subject_id = int(v[0])
        name = v[1]
        subtopics[subtopic_id] = (subject_id, name)
        subtopics_by_subject.setdefault(subject_id, []).append((subtopic_id, name))

    exams = raw.get("exams", {})
    exam_subjects = {k: [int(x) for x in v] for k, v in raw.get("exam_subjects", {}).items()}

    return {
        "subjects": subjects,
        "subtopics": subtopics,
        "subtopics_by_subject": subtopics_by_subject,
        "exams": exams,
        "exam_subjects": exam_subjects,
    }


def build_reference_context(ref):
    """Build a compact, LLM-friendly text block describing every valid
    subject_id / subtopic_id / exam_code so Gemini only ever picks real ids."""
    lines = []
    lines.append("VALID SUBJECTS AND SUBTOPICS (use ONLY these ids):")
    for subject_id, name in sorted(ref["subjects"].items()):
        subs = ref["subtopics_by_subject"].get(subject_id, [])
        sub_str = ", ".join(f"{sid}:{sname}" for sid, sname in subs) or "(no subtopics defined)"
        lines.append(f"- subject_id={subject_id} \"{name}\" -> subtopics: {sub_str}")

    lines.append("")
    lines.append("VALID EXAM CODES (use ONLY these in applicable_exam_codes, comma-separated if multiple):")
    for code, info in sorted(ref["exams"].items()):
        lines.append(f"- {code} : {info['display_name']} ({info['exam_name']}, level={info['exam_level']}, category={info['exam_category']})")

    return "\n".join(lines)


# --------------------------------------------------------------------------
# PDF page-batching
# --------------------------------------------------------------------------

def split_pdf_into_batches(pdf_path, pages_per_batch):
    reader = PdfReader(pdf_path)
    total_pages = len(reader.pages)
    # Write batch files next to the source PDF (already known-writable --
    # that's where the caller just saved the upload to) instead of the
    # current working directory, which may not be writable and would
    # otherwise litter whatever folder the script happens to be run from.
    out_dir = os.path.dirname(os.path.abspath(pdf_path)) or "."
    run_id = uuid.uuid4().hex[:8]
    batches = []
    for start in range(0, total_pages, pages_per_batch):
        end = min(start + pages_per_batch, total_pages)
        writer = PdfWriter()
        for p in range(start, end):
            writer.add_page(reader.pages[p])
        buf_path = os.path.join(out_dir, f"__qbank_batch_{run_id}_{start}_{end}.pdf")
        with open(buf_path, "wb") as f:
            writer.write(f)
        batches.append((buf_path, start + 1, end))
    return batches, total_pages


# --------------------------------------------------------------------------
# Gemini call
# --------------------------------------------------------------------------

RESPONSE_SCHEMA = {
    "type": "object",
    "properties": {
        "questions": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "question_text": {"type": "string"},
                    "option1": {"type": "string"},
                    "option2": {"type": "string"},
                    "option3": {"type": "string"},
                    "option4": {"type": "string"},
                    "correct_answer": {"type": "integer", "description": "1, 2, 3 or 4"},
                    "difficulty": {"type": "integer", "description": "1=Easy, 2=Medium, 3=Hard"},
                    "subject_id": {"type": "integer"},
                    "subtopic_id": {"type": "integer"},
                    "applicable_exam_codes": {
                        "type": "string",
                        "description": "Comma-separated exam_code values from the provided valid list",
                    },
                    "explanation": {"type": "string"},
                },
                "required": [
                    "question_text", "option1", "option2", "option3", "option4",
                    "correct_answer", "difficulty", "subject_id", "subtopic_id",
                    "applicable_exam_codes", "explanation",
                ],
            },
        }
    },
    "required": ["questions"],
}


def build_prompt(reference_context, exam_hint, page_range_note):
    hint_line = (
        f'This PDF/page-range is from the exam with exam_code "{exam_hint}". '
        f"Always include \"{exam_hint}\" in applicable_exam_codes for every question, "
        "and add any additional exam_codes only if the question would clearly also fit "
        "that exam's syllabus."
        if exam_hint
        else "No specific source exam was given -- infer the most likely applicable_exam_codes "
        "from the question's style, level and syllabus."
    )

    return f"""You are helping build a question bank for an exam-prep platform.

Read the attached PDF{page_range_note} and extract EVERY multiple-choice question (MCQ) in it,
in the order they appear. For each question:

1. Reproduce the question_text exactly (clean up OCR/formatting noise, do not add commentary).
2. Reproduce all 4 answer options exactly as option1..option4, in original order.
3. Determine correct_answer as an integer 1-4 (1=option1 ... 4=option4). If an official answer
   key is present in the PDF, use it. Otherwise work out the correct answer yourself using sound
   subject-matter reasoning -- never guess randomly.
4. Classify the question using ONLY the following real subject_id and subtopic_id values (do not
   invent new ids, and make sure the subtopic_id you choose actually belongs to the subject_id you
   chose):

{reference_context}

5. Assign difficulty: 1 (Easy), 2 (Medium), or 3 (Hard).
6. Set applicable_exam_codes (comma-separated, using ONLY exam_code values listed above).
   {hint_line}
7. Write a clear, self-contained explanation (2-5 sentences) of why the correct answer is
   correct, showing the key reasoning/formula/fact -- written so a student could learn from it
   even without seeing the source PDF.

Skip anything that is not a genuine MCQ (instructions, passages without their own question,
answer-key-only pages, etc.), unless it is a passage-based question -- in that case, include the
relevant passage text inside question_text along with the question.

MATH, CHEMISTRY & NOTATION RULES (very important -- these fields are shown to students as plain
text, with NO LaTeX renderer, so raw LaTeX source shows up as broken text on screen):
- Do NOT use LaTeX or any backslash markup anywhere: no \\(...\\), no \\[...\\], no $...$, no
  \\text{{}}, no \\frac{{}}{{}}, no ^{{}} or _{{}} with braces, no \\alpha/\\beta/etc. commands.
- Write everything in plain, ordinary text using normal keyboard characters and Unicode symbols.
- Superscripts (exponents, ions, ordinals): use a bare caret with no braces (I^B, x^2), a Unicode
  superscript character where natural (B+, 10^-3 as 10\u207b\u00b3), or just spell it out
  ("21st chromosome", not 21^{{st}}).
- Subscripts: use Unicode subscript digits where natural (H2O as H\u2082O) or just write the
  number inline (CO2).
- Fractions: write as "a/b" or "a divided by b".
- Greek letters and symbols: use the actual character (\u03b1, \u03b2, \u0394, \u00d7, \u00f7,
  \u00b1, \u2264, \u2265, \u2192) instead of a LaTeX command name.
- Genotypes/genetics notation: write plainly, e.g. "I^B i", "I^A i", "ii" -- never wrap single
  letters in \\text{{}}.
  GOOD: "father's genotype is I^B i, mother's is I^A i, child's is ii"
  BAD:  "father's genotype is \\(\\text{{I}}^\\text{{B}}\\text{{i}}\\)"
- Ordinal chromosome numbers: write "21st chromosome", "16th chromosome" -- never
  "21^{{\\text{{st}}}}".

Return your answer strictly following the provided JSON schema. Do not include any text outside
the JSON.
"""


def call_gemini(api_key, model, pdf_path, prompt, thinking_level, max_retries=3):
    with open(pdf_path, "rb") as f:
        pdf_b64 = base64.b64encode(f.read()).decode("utf-8")

    url = GEMINI_ENDPOINT.format(model=model)
    payload = {
        "contents": [
            {
                "role": "user",
                "parts": [
                    {"inline_data": {"mime_type": "application/pdf", "data": pdf_b64}},
                    {"text": prompt},
                ],
            }
        ],
        "generationConfig": {
            "responseMimeType": "application/json",
            "responseSchema": RESPONSE_SCHEMA,
        },
    }
    if thinking_level:
        payload["generationConfig"]["thinkingConfig"] = {"thinkingLevel": thinking_level}

    headers = {"Content-Type": "application/json", "x-goog-api-key": api_key}

    last_err = None
    for attempt in range(1, max_retries + 1):
        try:
            resp = requests.post(url, headers=headers, json=payload, timeout=600)
            if resp.status_code == 200:
                data = resp.json()
                candidates = data.get("candidates", [])
                if not candidates:
                    raise RuntimeError(f"No candidates returned: {json.dumps(data)[:500]}")
                parts = candidates[0]["content"]["parts"]
                text = "".join(p.get("text", "") for p in parts)
                return json.loads(text)
            else:
                last_err = f"HTTP {resp.status_code}: {resp.text[:800]}"
                if resp.status_code in (429, 500, 502, 503, 504):
                    wait = 5 * attempt
                    print(f"  ...retrying in {wait}s ({last_err})", file=sys.stderr)
                    time.sleep(wait)
                    continue
                break
        except requests.RequestException as e:
            last_err = str(e)
            time.sleep(5 * attempt)
    raise RuntimeError(f"Gemini call failed after {max_retries} attempts: {last_err}")


def call_groq(api_key, model, pdf_path, prompt_text, max_retries=3):
    """Automatic per-batch fallback used when call_gemini() fails for a
    batch (network error, quota, or a blocked/RECITATION response). Groq's
    chat-completions API can't read raw PDF bytes the way Gemini's native
    PDF understanding can, so the batch's own (already-small) page range is
    text-extracted with pypdf first."""
    reader = PdfReader(pdf_path)
    text = "\n\n".join((page.extract_text() or "") for page in reader.pages)
    if len(text.strip()) < 100:
        raise RuntimeError("not enough extractable text in this batch for the Groq fallback "
                            "(likely a scanned/image-only page range)")

    system_msg = (
        "You are a precise data-extraction engine. Respond with ONLY a single valid JSON object "
        "-- no markdown code fences, no commentary, no text outside the JSON -- that matches "
        "exactly this JSON schema:\n\n" + json.dumps(RESPONSE_SCHEMA)
    )
    user_msg = prompt_text + "\n\nDocument text:\n\n" + text

    headers = {"Content-Type": "application/json", "Authorization": f"Bearer {api_key}"}
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": system_msg},
            {"role": "user", "content": user_msg},
        ],
        "temperature": 0.2,
        "response_format": {"type": "json_object"},
    }

    last_err = None
    for attempt in range(1, max_retries + 1):
        try:
            resp = requests.post(GROQ_ENDPOINT, headers=headers, json=payload, timeout=600)
            if resp.status_code == 200:
                data = resp.json()
                content = data["choices"][0]["message"]["content"].strip()
                content = re.sub(r"^```(?:json)?", "", content, flags=re.IGNORECASE).strip()
                content = re.sub(r"```$", "", content).strip()
                return json.loads(content)
            else:
                last_err = f"HTTP {resp.status_code}: {resp.text[:500]}"
                if resp.status_code in (429, 500, 502, 503, 504):
                    wait = 5 * attempt
                    print(f"  ...Groq retrying in {wait}s ({last_err})", file=sys.stderr)
                    time.sleep(wait)
                    continue
                break
        except requests.RequestException as e:
            last_err = str(e)
            time.sleep(5 * attempt)
    raise RuntimeError(f"Groq call failed after {max_retries} attempts: {last_err}")


# --------------------------------------------------------------------------
# Validation + SQL generation
# --------------------------------------------------------------------------

def validate_and_fix(q, ref, exam_hint, warnings):
    for field in ("question_text", "option1", "option2", "option3", "option4", "explanation"):
        if field in q and isinstance(q[field], str):
            q[field] = sanitize_math_notation(q[field])

    subject_id = q.get("subject_id")
    subtopic_id = q.get("subtopic_id")

    if subject_id not in ref["subjects"]:
        warnings.append(f"Unknown subject_id {subject_id} for question: {q['question_text'][:60]!r} -- skipped")
        return None

    if subtopic_id not in ref["subtopics"]:
        # fall back to the first subtopic under that subject, if any
        candidates = ref["subtopics_by_subject"].get(subject_id, [])
        if candidates:
            fixed_id = candidates[0][0]
            warnings.append(
                f"Unknown subtopic_id {subtopic_id} -- fell back to {fixed_id} "
                f"for question: {q['question_text'][:60]!r}"
            )
            subtopic_id = fixed_id
        else:
            warnings.append(f"Unknown subtopic_id {subtopic_id} and no subtopics exist for "
                             f"subject_id {subject_id} -- skipped")
            return None
    else:
        real_subject_of_subtopic = ref["subtopics"][subtopic_id][0]
        if real_subject_of_subtopic != subject_id:
            warnings.append(
                f"subtopic_id {subtopic_id} belongs to subject_id {real_subject_of_subtopic}, "
                f"not {subject_id} -- corrected subject_id"
            )
            subject_id = real_subject_of_subtopic

    codes_raw = [c.strip() for c in str(q.get("applicable_exam_codes", "")).split(",") if c.strip()]
    valid_codes = [c for c in codes_raw if c in ref["exams"]]
    if not valid_codes:
        if exam_hint and exam_hint in ref["exams"]:
            valid_codes = [exam_hint]
            warnings.append(
                f"No valid applicable_exam_codes returned -- defaulted to hint '{exam_hint}' "
                f"for question: {q['question_text'][:60]!r}"
            )
        else:
            warnings.append(
                f"No valid applicable_exam_codes and no exam hint -- skipped "
                f"question: {q['question_text'][:60]!r}"
            )
            return None

    try:
        correct_answer = int(q["correct_answer"])
    except (TypeError, ValueError):
        warnings.append(f"Invalid correct_answer -- skipped question: {q['question_text'][:60]!r}")
        return None
    if correct_answer not in (1, 2, 3, 4):
        warnings.append(f"correct_answer out of range ({correct_answer}) -- skipped question: "
                         f"{q['question_text'][:60]!r}")
        return None

    try:
        difficulty = int(q.get("difficulty", 2))
    except (TypeError, ValueError):
        difficulty = 2
    if difficulty not in (1, 2, 3):
        difficulty = 2

    q["subject_id"] = subject_id
    q["subtopic_id"] = subtopic_id
    q["applicable_exam_codes"] = ",".join(valid_codes)
    q["correct_answer"] = correct_answer
    q["difficulty"] = difficulty
    return q


_MATH_SYMBOL_MAP = {
    r"\alpha": "\u03b1", r"\beta": "\u03b2", r"\gamma": "\u03b3", r"\delta": "\u03b4",
    r"\Delta": "\u0394", r"\theta": "\u03b8", r"\lambda": "\u03bb", r"\mu": "\u03bc",
    r"\pi": "\u03c0", r"\sigma": "\u03c3", r"\omega": "\u03c9", r"\Omega": "\u03a9",
    r"\times": "\u00d7", r"\div": "\u00f7", r"\pm": "\u00b1", r"\leq": "\u2264",
    r"\geq": "\u2265", r"\neq": "\u2260", r"\rightarrow": "\u2192", r"\to": "\u2192",
    r"\cdot": "\u00b7", r"\infty": "\u221e", r"\circ": "\u00b0",
}
_SUPERSCRIPT_MAP = {
    "0": "\u2070", "1": "\u00b9", "2": "\u00b2", "3": "\u00b3", "4": "\u2074",
    "5": "\u2075", "6": "\u2076", "7": "\u2077", "8": "\u2078", "9": "\u2079",
    "+": "\u207a", "-": "\u207b", "=": "\u207c", "(": "\u207d", ")": "\u207e",
}
_SUBSCRIPT_MAP = {
    "0": "\u2080", "1": "\u2081", "2": "\u2082", "3": "\u2083", "4": "\u2084",
    "5": "\u2085", "6": "\u2086", "7": "\u2087", "8": "\u2088", "9": "\u2089",
    "+": "\u208a", "-": "\u208b",
}


def _script_map(chars, table):
    """Map every char in `chars` through `table`; return None if any char has no mapping."""
    out = []
    for ch in chars:
        if ch not in table:
            return None
        out.append(table[ch])
    return "".join(out)


def sanitize_math_notation(text):
    """Best-effort cleanup of stray LaTeX that slipped past the prompt instructions, so
    question/option/explanation text never shows raw backslash-markup to students on a
    platform with no LaTeX renderer."""
    if not text:
        return text

    for macro, repl in _MATH_SYMBOL_MAP.items():
        text = text.replace(macro, repl)

    # \frac{a}{b} -> (a)/(b)
    text = re.sub(r"\\frac\{([^{}]*)\}\{([^{}]*)\}", r"(\1)/(\2)", text)

    # Unwrap \text{...} -- run a few passes for adjacent/back-to-back runs
    for _ in range(4):
        text = re.sub(r"\\text\{([^{}]*)\}", r"\1", text)

    # Strip math-mode delimiters entirely
    for token in (r"\(", r"\)", r"\[", r"\]", "$$"):
        text = text.replace(token, "")

    def _sup_braced(m):
        mapped = _script_map(m.group(1), _SUPERSCRIPT_MAP)
        if mapped:
            return mapped
        # A lone non-alphanumeric symbol (e.g. a degree sign) already reads fine on its
        # own -- don't glue a stray caret onto it.
        if len(m.group(1)) == 1 and not m.group(1).isalnum():
            return m.group(1)
        return "^" + m.group(1)
    text = re.sub(r"\^\{([^{}]+)\}", _sup_braced, text)

    def _sup_bare(m):
        mapped = _script_map(m.group(1), _SUPERSCRIPT_MAP)
        return mapped if mapped else m.group(0)
    text = re.sub(r"\^([0-9+\-])", _sup_bare, text)

    def _sub_braced(m):
        mapped = _script_map(m.group(1), _SUBSCRIPT_MAP)
        return mapped if mapped else m.group(1)
    text = re.sub(r"_\{([^{}]+)\}", _sub_braced, text)

    def _sub_bare(m):
        mapped = _script_map(m.group(1), _SUBSCRIPT_MAP)
        return mapped if mapped else m.group(1)
    text = re.sub(r"_([0-9])", _sub_bare, text)

    # Any remaining unknown \command{...} -> just its inner content
    for _ in range(3):
        text = re.sub(r"\\[a-zA-Z]+\{([^{}]*)\}", r"\1", text)
    # Any remaining bare \command -> drop the backslash, keep the word
    text = re.sub(r"\\([a-zA-Z]+)", r"\1", text)

    text = text.replace("{}", "")
    text = re.sub(r"[ \t]{2,}", " ", text)
    return text.strip()


def content_hash_for(question_text):
    return hashlib.sha256(question_text.strip().lower().encode("utf-8")).hexdigest()


def build_result_rows(questions, seen_hashes):
    """Turn validated question dicts into plain row-dicts ready for a direct,
    parameterized DB insert on the PHP side -- no SQL text is generated here at all,
    which is what makes this immune to the "syntax error near ...stray semicolon/quote
    in question text" class of bugs a hand-built SQL string is prone to."""
    rows = []
    duplicates = 0
    for q in questions:
        chash = content_hash_for(q["question_text"])
        dupe_flag = 1 if chash in seen_hashes else 0
        if dupe_flag:
            duplicates += 1
        else:
            seen_hashes.add(chash)

        rows.append({
            "subject_id": q["subject_id"],
            "subtopic_id": q["subtopic_id"],
            "applicable_exam_codes": q["applicable_exam_codes"],
            "difficulty": q["difficulty"],
            "question_type": "MCQ",
            "question_text": q["question_text"],
            "option1": q["option1"],
            "option2": q["option2"],
            "option3": q["option3"],
            "option4": q["option4"],
            "correct_answer": q["correct_answer"],
            "explanation": q["explanation"],
            "content_hash": chash,
            "generated_by_ai": 1,
            "is_verified": 0,
            "is_active": 1,
            "dupe": dupe_flag,
        })
    return rows, duplicates


# --------------------------------------------------------------------------
# Main
# --------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="Extract questions from a PDF into qbank INSERT SQL using Gemini")
    parser.add_argument("--pdf", required=True, help="Path to the question-paper PDF")
    parser.add_argument("--api-key", default=None, help="Gemini API key (else GEMINI_API_KEY env var, else prompted)")
    parser.add_argument("--model", default="gemini-3.7-flash", help="Gemini model id")
    parser.add_argument("--exam-hint", default=None, help="exam_code this paper belongs to, e.g. NEET_UG")
    parser.add_argument("--subjects-sql", default="subjects.sql")
    parser.add_argument("--subtopics-sql", default="subtopics.sql")
    parser.add_argument("--entrance-exams-sql", default="entrance_exams.sql")
    parser.add_argument("--exam-subjects-sql", default="exam_subjects.sql")
    parser.add_argument("--ref-json", default=None,
                         help="Load reference data from this JSON file instead of the --*-sql dumps "
                              "(used by the qbank PHP admin panel to pass live DB data)")
    parser.add_argument("--groq-api-key", default=None,
                         help="Groq API key. If set, any batch where Gemini fails (network error, "
                              "quota, or a blocked/RECITATION response) automatically retries via Groq.")
    parser.add_argument("--groq-model", default="llama-3.3-70b-versatile", help="Groq model id for the fallback")
    parser.add_argument("--pages-per-batch", type=int, default=12, help="Split large PDFs into batches of N pages")
    parser.add_argument("--thinking-level", default="low", choices=["low", "medium", "high", ""],
                         help="Gemini thinking level (empty to omit)")
    args = parser.parse_args()

    api_key = args.api_key or os.environ.get("GEMINI_API_KEY")
    if not api_key:
        api_key = getpass.getpass("Enter your Gemini API key: ").strip()
    if not api_key:
        print("No API key provided.", file=sys.stderr)
        sys.exit(1)

    if args.exam_hint:
        exam_hint = args.exam_hint.strip().upper()
    else:
        exam_hint = None

    if args.ref_json:
        print("Loading reference data from the live database...")
        ref = load_reference_data_from_json(args.ref_json)
    else:
        print("Loading reference data from SQL dumps...")
        ref = load_reference_data(
            args.subjects_sql, args.subtopics_sql, args.entrance_exams_sql, args.exam_subjects_sql
        )
    print(f"  {len(ref['subjects'])} subjects, {len(ref['subtopics'])} subtopics, "
          f"{len(ref['exams'])} exams loaded.")

    if exam_hint and exam_hint not in ref["exams"]:
        print(f"WARNING: exam-hint '{exam_hint}' is not a known exam_code in entrance_exams.sql. "
              f"Proceeding anyway; Gemini will infer instead.", file=sys.stderr)

    reference_context = build_reference_context(ref)

    print(f"Splitting PDF into batches of {args.pages_per_batch} pages...")
    batches, total_pages = split_pdf_into_batches(args.pdf, args.pages_per_batch)
    print(f"  {total_pages} pages -> {len(batches)} batch(es).")

    all_questions = []
    warnings = []
    groq_used_count = 0

    thinking_level = args.thinking_level or None

    for i, (batch_path, start_page, end_page) in enumerate(batches, 1):
        page_range_note = f" (pages {start_page}-{end_page} of the original document)"
        print(f"[{i}/{len(batches)}] Calling Gemini for pages {start_page}-{end_page}...")
        prompt = build_prompt(reference_context, exam_hint, page_range_note)

        result = None
        used_groq = False
        try:
            result = call_gemini(api_key, args.model, batch_path, prompt, thinking_level)
        except Exception as e:
            print(f"  Gemini failed on this batch: {e}")
            if args.groq_api_key:
                print(f"  Falling back to Groq ({args.groq_model}) for this batch...")
                try:
                    result = call_groq(args.groq_api_key, args.groq_model, batch_path, prompt)
                    used_groq = True
                except Exception as e2:
                    print(f"  ERROR on batch {i}: Gemini and Groq both failed ({e2})", file=sys.stderr)
                    warnings.append(f"Batch pages {start_page}-{end_page} failed entirely "
                                     f"(Gemini + Groq): {e2}")
            else:
                print(f"  ERROR on batch {i}: {e}", file=sys.stderr)
                warnings.append(f"Batch pages {start_page}-{end_page} failed entirely: {e}")

        if os.path.exists(batch_path):
            os.remove(batch_path)

        if result is None:
            continue

        questions = result.get("questions", [])
        suffix = " (via Groq fallback)" if used_groq else ""
        print(f"  -> {len(questions)} question(s) extracted{suffix}.")
        if used_groq:
            groq_used_count += 1

        for q in questions:
            fixed = validate_and_fix(q, ref, exam_hint, warnings)
            if fixed:
                all_questions.append(fixed)

    if groq_used_count:
        print(f"Groq fallback was used for {groq_used_count} of {len(batches)} batch(es).")

    if not all_questions:
        print("No valid questions were extracted. See warnings below.", file=sys.stderr)
        for w in warnings:
            print(f"  - {w}", file=sys.stderr)
        sys.exit(1)

    seen_hashes = set()
    rows, duplicates = build_result_rows(all_questions, seen_hashes)

    print(f"\nDone. {len(rows)} question(s) extracted, {duplicates} flagged as likely duplicates.")

    if warnings:
        print(f"\n{len(warnings)} warning(s) during processing:")
        for w in warnings:
            print(f"  - {w}")

    # Hand the structured rows back to the calling PHP process over stdout --
    # no .sql (or any other) file is written to disk. PHP recognises this
    # sentinel-prefixed line, pulls the JSON out of it, and inserts each row
    # into qbank itself via a parameterized query.
    emit_result(rows)


if __name__ == "__main__":
    main()
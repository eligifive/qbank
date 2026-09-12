<?php
/**
 * generate.php
 * ---------------------------------------------------------------------
 * "Generate Questions" tool.
 *
 * Admin uploads a previous-year question paper (PDF or .docx). The file
 * (or its extracted text, for .docx) is sent to Gemini together with the
 * live subjects / subtopics / entrance_exams data pulled straight from
 * this app's database, so every question Gemini returns is classified
 * against real, existing ids. Gemini also analyzes the paper itself to
 * work out which exam it belongs to -- no manual exam hint is required.
 *
 * The whole pipeline runs inline on this page and streams a live log to
 * the browser as it progresses (file received -> reference data loaded
 * -> sent to Gemini -> validated/mapped -> SQL written), then offers the
 * finished qbank INSERT SQL file as a download.
 *
 * Requires: PHP with curl + zip extensions enabled (both are standard in
 * XAMPP). Nothing else to install.
 * ---------------------------------------------------------------------
 */

require "auth.php";
require_login();
include "db.php";

/* =======================================================================
 *  AI provider configuration
 * ---------------------------------------------------------------------
 *  Paste your keys below. Gemini is used first; if the Gemini call fails
 *  for ANY reason (network error, blocked/RECITATION response, quota,
 *  etc.) this file automatically falls back to Groq using the same
 *  reference data and prompt. No key or model is ever entered through
 *  the browser -- everything is configured here on the server only.
 * ===================================================================== */

define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.7-flash');

define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'openai/gpt-oss-20b');

/* PDFs are handed off to scripts/pdf_to_qbank.py, which splits the paper into
 * page-batches and calls Gemini (falling back to Groq) once per batch -- the
 * same approach used from the command line, which avoids RECITATION blocks
 * that a single whole-document request can trigger on well-known papers. */
define('PYTHON_BIN', 'python'); // 'python3' on macOS/Linux, or a full path if it's not on PATH
define('PDF_TO_QBANK_SCRIPT', __DIR__ . '/scripts/pdf_to_qbank.py');
define('PAGES_PER_BATCH', 12);
define('SCRIPT_MAX_RUNTIME_SECONDS', 1800); // hard safety cap (30 min) so a stuck run can't hang forever

set_time_limit(0);
ini_set('max_execution_time', '0');

$UPLOAD_DIR = __DIR__ . '/uploads/';
$OUTPUT_DIR = __DIR__ . '/generated/';
if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0755, true); }
if (!is_dir($OUTPUT_DIR)) { @mkdir($OUTPUT_DIR, 0755, true); }
foreach ([$UPLOAD_DIR, $OUTPUT_DIR] as $dir) {
    $ht = $dir . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\nRequire all denied\n</FilesMatch>\n");
    }
}

/* =======================================================================
 *  Reference data (subjects / subtopics / entrance_exams) straight from DB
 * ===================================================================== */

function loadReferenceData(PDO $pdo): array {
    $subjects = [];
    $stmt = $pdo->query("SELECT subject_id, subject_name FROM subjects WHERE is_active = 1");
    foreach ($stmt as $row) {
        $subjects[(int)$row['subject_id']] = $row['subject_name'];
    }

    $subtopics = [];
    $subtopicsBySubject = [];
    $stmt = $pdo->query("SELECT subtopic_id, subject_id, subtopic_name FROM subtopics WHERE is_active = 1");
    foreach ($stmt as $row) {
        $sid = (int)$row['subtopic_id'];
        $subjId = (int)$row['subject_id'];
        $subtopics[$sid] = ['subject_id' => $subjId, 'name' => $row['subtopic_name']];
        $subtopicsBySubject[$subjId][] = ['subtopic_id' => $sid, 'name' => $row['subtopic_name']];
    }

    $exams = [];
    $stmt = $pdo->query("SELECT exam_id, exam_code, display_name, exam_name, exam_level, exam_category FROM entrance_exams");
    foreach ($stmt as $row) {
        $exams[$row['exam_code']] = $row;
    }

    return [
        'subjects' => $subjects,
        'subtopics' => $subtopics,
        'subtopicsBySubject' => $subtopicsBySubject,
        'exams' => $exams,
    ];
}

function buildReferenceContext(array $ref): string {
    $lines = [];
    $lines[] = "VALID SUBJECTS AND SUBTOPICS (use ONLY these ids -- never invent new ones):";
    $subjects = $ref['subjects'];
    ksort($subjects);
    foreach ($subjects as $subjectId => $name) {
        $subs = $ref['subtopicsBySubject'][$subjectId] ?? [];
        if ($subs) {
            $subStr = implode(', ', array_map(fn($s) => $s['subtopic_id'] . ':' . $s['name'], $subs));
        } else {
            $subStr = '(no subtopics defined)';
        }
        $lines[] = "- subject_id=$subjectId \"$name\" -> subtopics: $subStr";
    }
    $lines[] = "";
    $lines[] = "VALID EXAM CODES (use ONLY these in detected_exam_code / applicable_exam_codes):";
    foreach ($ref['exams'] as $code => $info) {
        $lines[] = "- $code : {$info['display_name']} ({$info['exam_name']}, level={$info['exam_level']}, category={$info['exam_category']})";
    }
    return implode("\n", $lines);
}

/* =======================================================================
 *  File readers
 * ===================================================================== */

function extractDocxText(string $path): string {
    if (!class_exists('ZipArchive')) {
        throw new Exception('The PHP zip extension is required to read .docx files but is not enabled on this server.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Could not open the Word document. Is it a valid, non-password-protected .docx file?');
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        throw new Exception('Could not read document content from the .docx file.');
    }
    $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />', '<w:tab/>', '<w:tab />'], ["\n", "\n", "\n", "\t", "\t"], $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function roughPdfPageCount(string $filePath): ?int {
    $content = @file_get_contents($filePath);
    if ($content === false) return null;
    $count = preg_match_all('/\/Type\s*\/Page[^s]/', $content);
    return $count > 0 ? $count : null;
}

/* =======================================================================
 *  Gemini call
 * ===================================================================== */

function geminiResponseSchema(): array {
    return [
        'type' => 'object',
        'properties' => [
            'detected_exam_code' => [
                'type' => 'string',
                'description' => 'The single exam_code (from the valid list) this paper most likely belongs to.',
            ],
            'detected_exam_reason' => [
                'type' => 'string',
                'description' => 'One short sentence on why this exam was identified.',
            ],
            'questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question_text' => ['type' => 'string'],
                        'option1' => ['type' => 'string'],
                        'option2' => ['type' => 'string'],
                        'option3' => ['type' => 'string'],
                        'option4' => ['type' => 'string'],
                        'correct_answer' => ['type' => 'integer', 'description' => '1, 2, 3 or 4'],
                        'difficulty' => ['type' => 'integer', 'description' => '1=Easy, 2=Medium, 3=Hard'],
                        'subject_id' => ['type' => 'integer'],
                        'subtopic_id' => ['type' => 'integer'],
                        'applicable_exam_codes' => [
                            'type' => 'string',
                            'description' => 'Comma-separated exam_code values from the provided valid list',
                        ],
                        'explanation' => ['type' => 'string'],
                    ],
                    'required' => [
                        'question_text', 'option1', 'option2', 'option3', 'option4',
                        'correct_answer', 'difficulty', 'subject_id', 'subtopic_id',
                        'applicable_exam_codes', 'explanation',
                    ],
                ],
            ],
        ],
        'required' => ['questions'],
    ];
}

function buildPrompt(string $referenceContext): string {
    return <<<PROMPT
You are helping build a question bank for an exam-prep platform.

Read the attached document (a previous-year question paper, either the original PDF or text
extracted from a Word file) and extract EVERY multiple-choice question (MCQ) in it, in the order
they appear.

First, analyze the document as a whole (title, header/footer, instructions, question style,
subject mix, difficulty level and syllabus coverage) to work out which SINGLE exam from the valid
list below this paper most likely belongs to. Put that exam_code in detected_exam_code and a
one-line justification in detected_exam_reason. Do not skip this -- always provide your best
single guess even if you are not fully certain.

For each question:
1. Reproduce the question_text exactly (clean up OCR/formatting noise, do not add commentary).
2. Reproduce all 4 answer options exactly as option1..option4, in original order.
3. Determine correct_answer as an integer 1-4 (1=option1 ... 4=option4). If an official answer key
   is present in the document, use it. Otherwise work out the correct answer yourself using sound
   subject-matter reasoning -- never guess randomly.
4. Classify the question using ONLY the following real subject_id and subtopic_id values (never
   invent new ids, and make sure the subtopic_id you choose actually belongs to the subject_id you
   chose):

{$referenceContext}

5. Assign difficulty: 1 (Easy), 2 (Medium), or 3 (Hard).
6. Set applicable_exam_codes (comma-separated, using ONLY exam_code values listed above). Default
   this to the detected_exam_code, and add extra codes only if the question content would also
   clearly fit another listed exam's syllabus.
7. Write a clear, self-contained explanation (2-5 sentences) of why the correct answer is correct,
   showing the key reasoning/formula/fact -- written so a student could learn from it without
   needing to see the source document.

Skip anything that is not a genuine MCQ (instructions, passages without their own question,
answer-key-only pages, etc.), unless it is a passage-based question -- in that case, include the
relevant passage text inside question_text along with the question.

MATH, CHEMISTRY & NOTATION RULES (very important -- these fields are shown to students as plain
text, with NO LaTeX renderer, so raw LaTeX source shows up as broken text on screen):
- Do NOT use LaTeX or any backslash markup anywhere: no \\(...\\), no \\[...\\], no \$...\$, no
  \\text{}, no \\frac{}{}, no ^{} or _{} with braces, no \\alpha/\\beta/etc. commands.
- Write everything in plain, ordinary text using normal keyboard characters and Unicode symbols.
- Superscripts (exponents, ions, ordinals): use a bare caret with no braces (I^B, x^2), a Unicode
  superscript character where natural (B+, 10^-3 as 10⁻³), or just spell it out ("21st
  chromosome", not 21^{st}).
- Subscripts: use Unicode subscript digits where natural (H2O as H₂O) or just write the number
  inline (CO2).
- Fractions: write as "a/b" or "a divided by b".
- Greek letters and symbols: use the actual character (α, β, Δ, ×, ÷, ±, ≤, ≥, →) instead of a
  LaTeX command name.
- Genotypes/genetics notation: write plainly, e.g. "I^B i", "I^A i", "ii" -- never wrap single
  letters in \\text{}.
  GOOD: "father's genotype is I^B i, mother's is I^A i, child's is ii"
  BAD:  "father's genotype is \\(\\text{I}^\\text{B}\\text{i}\\)"
- Ordinal chromosome numbers: write "21st chromosome", "16th chromosome" -- never "21^{\\text{st}}".

Return your answer strictly following the provided JSON schema. Do not include any text outside
the JSON.
PROMPT;
}

function callGemini(string $apiKey, string $model, array $parts, array $schema, int $timeout = 600): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent";
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => $parts,
        ]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Network error calling Gemini: $curlErr");
    }
    if ($httpCode !== 200) {
        throw new Exception("Gemini API returned HTTP $httpCode: " . substr($response, 0, 800));
    }

    $data = json_decode($response, true);
    $candidate = $data['candidates'][0] ?? null;
    if ($candidate === null) {
        throw new Exception("Gemini returned no candidates: " . substr($response, 0, 800));
    }

    if (!isset($candidate['content']['parts'])) {
        $finishReason = $candidate['finishReason'] ?? 'unknown';
        if ($finishReason === 'RECITATION') {
            throw new Exception("Gemini blocked the output (finishReason: RECITATION) because the extracted content closely matches material it has seen elsewhere online -- common with well-known past exam papers that are already indexed on sites like Scribd. Gemini refuses to reproduce it.");
        }
        if ($finishReason === 'SAFETY') {
            throw new Exception("Gemini blocked the output due to its safety filters (finishReason: SAFETY).");
        }
        throw new Exception("Unexpected Gemini response shape (finishReason: $finishReason): " . substr($response, 0, 800));
    }

    $text = '';
    foreach ($candidate['content']['parts'] as $p) {
        if (isset($p['text'])) $text .= $p['text'];
    }

    $parsed = json_decode($text, true);
    if ($parsed === null) {
        throw new Exception("Could not parse Gemini's JSON output. Raw start: " . substr($text, 0, 300));
    }
    return $parsed;
}

/* =======================================================================
 *  Groq fallback (used automatically whenever the Gemini call fails --
 *  network error, quota, or a blocked/RECITATION response)
 * ===================================================================== */

function callGroq(string $apiKey, string $model, string $promptText, array $schema, int $timeout = 600): array {
    $url = 'https://api.groq.com/openai/v1/chat/completions';

    $systemMsg = "You are a precise data-extraction engine. Respond with ONLY a single valid JSON object "
        . "-- no markdown code fences, no commentary, no text outside the JSON -- that matches exactly this "
        . "JSON schema:\n\n" . json_encode($schema);

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemMsg],
            ['role' => 'user', 'content' => $promptText],
        ],
        'temperature' => 0.2,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Network error calling Groq: $curlErr");
    }
    if ($httpCode !== 200) {
        throw new Exception("Groq API returned HTTP $httpCode: " . substr($response, 0, 800));
    }

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if ($text === null) {
        throw new Exception("Unexpected Groq response shape: " . substr($response, 0, 800));
    }

    // Some models add stray ```json fences even in JSON mode -- strip them defensively.
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?/i', '', $text);
    $text = preg_replace('/```$/', '', $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if ($parsed === null) {
        throw new Exception("Could not parse Groq's JSON output. Raw start: " . substr($text, 0, 300));
    }
    return $parsed;
}

/**
 * Very small best-effort PDF text extractor, used ONLY as input for the
 * Groq fallback (Groq's chat completions API can't read raw PDF bytes the
 * way Gemini's native PDF understanding can). It inflates FlateDecode
 * content streams and pulls text shown via Tj/TJ operators. It will not
 * work well on scanned/image-only PDFs.
 */
function extractPdfTextBasic(string $path): string {
    $data = @file_get_contents($path);
    if ($data === false) return '';

    $text = '';
    if (preg_match_all('/\d+\s+\d+\s+obj(.*?)stream\r?\n(.*?)\r?\nendstream/s', $data, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $objHeader = $m[1];
            $streamData = $m[2];

            if (stripos($objHeader, '/FlateDecode') !== false) {
                $decoded = @gzuncompress($streamData);
                if ($decoded === false) {
                    $decoded = @zlib_decode($streamData);
                }
                if ($decoded !== false && $decoded !== null) {
                    $streamData = $decoded;
                }
            }

            if (strpos($streamData, 'BT') === false) continue;

            if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)\s*T[jJ]/', $streamData, $tjMatches)) {
                foreach ($tjMatches[0] as $chunk) {
                    if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)/', $chunk, $strs)) {
                        foreach ($strs[0] as $s) {
                            $s = substr($s, 1, -1);
                            $s = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s);
                            $text .= $s;
                        }
                    }
                    $text .= ' ';
                }
            }
            $text .= "\n";
        }
    }

    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/* =======================================================================
 *  Validation against real DB ids
 * ===================================================================== */

function validateAndFix(array $q, array $ref, ?string $detectedExam, array &$warnings): ?array {
    foreach (['question_text', 'option1', 'option2', 'option3', 'option4', 'explanation'] as $f) {
        if (isset($q[$f]) && is_string($q[$f])) {
            $q[$f] = sanitizeMathNotation($q[$f]);
        }
    }

    $qtextShort = mb_strimwidth((string)($q['question_text'] ?? ''), 0, 70, '...');

    $subjectId = isset($q['subject_id']) ? (int)$q['subject_id'] : 0;
    if (!$subjectId || !isset($ref['subjects'][$subjectId])) {
        $warnings[] = "Unknown subject_id ({$subjectId}) -- skipped question: \"$qtextShort\"";
        return null;
    }

    $subtopicId = isset($q['subtopic_id']) ? (int)$q['subtopic_id'] : 0;
    if (!$subtopicId || !isset($ref['subtopics'][$subtopicId])) {
        $candidates = $ref['subtopicsBySubject'][$subjectId] ?? [];
        if (!empty($candidates)) {
            $fixedId = $candidates[0]['subtopic_id'];
            $warnings[] = "Unknown subtopic_id -- fell back to $fixedId for question: \"$qtextShort\"";
            $subtopicId = $fixedId;
        } else {
            $warnings[] = "Unknown subtopic_id and no subtopics exist for subject_id $subjectId -- skipped question: \"$qtextShort\"";
            return null;
        }
    } else {
        $realSubjectOfSubtopic = $ref['subtopics'][$subtopicId]['subject_id'];
        if ($realSubjectOfSubtopic != $subjectId) {
            $warnings[] = "subtopic_id $subtopicId belongs to subject_id $realSubjectOfSubtopic, not $subjectId -- corrected";
            $subjectId = $realSubjectOfSubtopic;
        }
    }

    $codesRaw = array_filter(array_map('trim', explode(',', (string)($q['applicable_exam_codes'] ?? ''))));
    $validCodes = array_values(array_filter($codesRaw, fn($c) => isset($ref['exams'][$c])));
    if (empty($validCodes)) {
        if ($detectedExam && isset($ref['exams'][$detectedExam])) {
            $validCodes = [$detectedExam];
            $warnings[] = "No valid applicable_exam_codes returned -- defaulted to detected exam '$detectedExam' for question: \"$qtextShort\"";
        } else {
            $warnings[] = "No valid applicable_exam_codes and no exam could be detected -- skipped question: \"$qtextShort\"";
            return null;
        }
    }

    $correctAnswer = isset($q['correct_answer']) ? (int)$q['correct_answer'] : 0;
    if ($correctAnswer < 1 || $correctAnswer > 4) {
        $warnings[] = "Invalid correct_answer -- skipped question: \"$qtextShort\"";
        return null;
    }

    $difficulty = isset($q['difficulty']) ? (int)$q['difficulty'] : 2;
    if ($difficulty < 1 || $difficulty > 3) $difficulty = 2;

    foreach (['option1', 'option2', 'option3', 'option4', 'question_text', 'explanation'] as $f) {
        if (!isset($q[$f]) || trim((string)$q[$f]) === '') {
            $warnings[] = "Missing field '$f' -- skipped question: \"$qtextShort\"";
            return null;
        }
    }

    $q['subject_id'] = $subjectId;
    $q['subtopic_id'] = $subtopicId;
    $q['applicable_exam_codes'] = implode(',', $validCodes);
    $q['correct_answer'] = $correctAnswer;
    $q['difficulty'] = $difficulty;
    return $q;
}

/* =======================================================================
 *  Best-effort LaTeX/backslash-markup cleanup -- a safety net in case the
 *  model still slips into LaTeX despite the prompt's plain-text rules.
 *  This platform has no LaTeX renderer, so any leftover \(...\), \text{},
 *  ^{...} etc. would otherwise show up as broken raw markup to students.
 * ===================================================================== */

function superscriptify(string $s): ?string {
    $map = ['0'=>'⁰','1'=>'¹','2'=>'²','3'=>'³','4'=>'⁴','5'=>'⁵','6'=>'⁶','7'=>'⁷','8'=>'⁸','9'=>'⁹','+'=>'⁺','-'=>'⁻','='=>'⁼','('=>'⁽',')'=>'⁾'];
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (!isset($map[$ch])) return null;
        $out .= $map[$ch];
    }
    return $out;
}

function subscriptify(string $s): ?string {
    $map = ['0'=>'₀','1'=>'₁','2'=>'₂','3'=>'₃','4'=>'₄','5'=>'₅','6'=>'₆','7'=>'₇','8'=>'₈','9'=>'₉','+'=>'₊','-'=>'₋'];
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (!isset($map[$ch])) return null;
        $out .= $map[$ch];
    }
    return $out;
}

function sanitizeMathNotation(string $text): string {
    if ($text === '') return $text;

    $symbolMap = [
        '\\alpha' => 'α', '\\beta' => 'β', '\\gamma' => 'γ', '\\delta' => 'δ',
        '\\Delta' => 'Δ', '\\theta' => 'θ', '\\lambda' => 'λ', '\\mu' => 'μ',
        '\\pi' => 'π', '\\sigma' => 'σ', '\\omega' => 'ω', '\\Omega' => 'Ω',
        '\\times' => '×', '\\div' => '÷', '\\pm' => '±', '\\leq' => '≤',
        '\\geq' => '≥', '\\neq' => '≠', '\\rightarrow' => '→', '\\to' => '→',
        '\\cdot' => '·', '\\infty' => '∞', '\\circ' => '°',
    ];
    $text = strtr($text, $symbolMap);

    // \frac{a}{b} -> (a)/(b)
    $text = preg_replace('/\\\\frac\{([^{}]*)\}\{([^{}]*)\}/', '($1)/($2)', $text);

    // Unwrap \text{...} -- a few passes for adjacent/back-to-back runs
    for ($i = 0; $i < 4; $i++) {
        $text = preg_replace('/\\\\text\{([^{}]*)\}/', '$1', $text);
    }

    // Strip math-mode delimiters entirely
    $text = str_replace(['\\(', '\\)', '\\[', '\\]', '$$'], '', $text);

    $text = preg_replace_callback('/\^\{([^{}]+)\}/', function ($m) {
        $mapped = superscriptify($m[1]);
        if ($mapped !== null) return $mapped;
        // A lone non-alphanumeric symbol (e.g. a degree sign) already reads fine on its
        // own -- don't glue a stray caret onto it.
        if (preg_match('/^[^\w]$/u', $m[1])) return $m[1];
        return '^' . $m[1];
    }, $text);
    $text = preg_replace_callback('/\^([0-9+\-])/', function ($m) {
        $mapped = superscriptify($m[1]);
        return $mapped !== null ? $mapped : $m[0];
    }, $text);

    $text = preg_replace_callback('/_\{([^{}]+)\}/', function ($m) {
        $mapped = subscriptify($m[1]);
        return $mapped !== null ? $mapped : $m[1];
    }, $text);
    $text = preg_replace_callback('/_([0-9])/', function ($m) {
        $mapped = subscriptify($m[1]);
        return $mapped !== null ? $mapped : $m[1];
    }, $text);

    // Any remaining unknown \command{...} -> just its inner content
    for ($i = 0; $i < 3; $i++) {
        $text = preg_replace('/\\\\[a-zA-Z]+\{([^{}]*)\}/', '$1', $text);
    }
    // Any remaining bare \command -> drop the backslash, keep the word
    $text = preg_replace('/\\\\([a-zA-Z]+)/', '$1', $text);

    $text = str_replace('{}', '', $text);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);

    return trim($text);
}

/**
 * Computes content_hash + dupe flag for a batch of already-validated question
 * rows, in place, using the same intra-batch logic the old SQL-file writer
 * used to apply -- but without ever writing anything to disk. This lets the
 * result banner show an accurate duplicate count immediately after
 * generation, before the "Dump into Qbank" button is even clicked.
 */
function flagDuplicatesInPlace(array &$questions): int {
    $seen = [];
    $dupes = 0;
    foreach ($questions as &$q) {
        $hash = contentHashFor((string)($q['question_text'] ?? ''));
        $q['content_hash'] = $hash;
        if (isset($seen[$hash])) {
            $q['dupe'] = 1;
            $dupes++;
        } else {
            $q['dupe'] = 0;
            $seen[$hash] = true;
        }
    }
    unset($q);
    return $dupes;
}

/* =======================================================================
 *  PDF -> scripts/pdf_to_qbank.py (batched Gemini + Groq fallback)
 * ===================================================================== */

/**
 * Runs the python extraction script and streams its stdout/stderr, one
 * line at a time, to $onLine(string $line, bool $isStderr) as it is
 * produced -- so the browser's live log shows the exact same
 * "[i/N] Calling Gemini for pages..." / "-> NN question(s) extracted."
 * progression you'd see running it directly from a terminal.
 */
/**
 * Runs the python extraction script and streams its output, one line at a
 * time, to $onLine(string $line, bool $isStderr) as it is produced -- so
 * the browser's live log shows the exact same
 * "[i/N] Calling Gemini for pages..." / "-> NN question(s) extracted."
 * progression you'd see running it directly from a terminal.
 *
 * IMPORTANT (Windows): this deliberately does NOT use proc_open's pipe
 * descriptors + stream_select()/non-blocking fread() to read live output.
 * That combination is well known to be unreliable on Windows -- PHP can end
 * up blocked on a pipe read that never resolves even though the child
 * process is running fine, which looks exactly like a permanent freeze with
 * no error. Instead, the child's stdout/stderr are redirected straight to
 * temp files, and this function just polls those files' size and reads
 * whatever's new -- which works identically and reliably on every OS.
 */
function runPdfToQbankScript(array $cmd, string $cwd, callable $onLine, int $maxRuntimeSeconds = 1800): array {
    $stdoutFile = tempnam(sys_get_temp_dir(), 'qbstdout_');
    $stderrFile = tempnam(sys_get_temp_dir(), 'qbstderr_');

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutFile, 'w'],
        2 => ['file', $stderrFile, 'w'],
    ];
    $process = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        @unlink($stdoutFile);
        @unlink($stderrFile);
        throw new Exception('Could not start the Python extraction script. Check PYTHON_BIN in generate.php and that Python (with "pip install requests pypdf") is installed.');
    }
    fclose($pipes[0]);

    $files = [1 => $stdoutFile, 2 => $stderrFile];
    $offsets = [1 => 0, 2 => 0];
    $buffers = [1 => '', 2 => ''];
    $stderrTail = [];
    $timedOut = false;

    $pump = function () use ($files, &$offsets, &$buffers, $onLine, &$stderrTail) {
        foreach ([1, 2] as $fd) {
            clearstatcache(true, $files[$fd]);
            $size = @filesize($files[$fd]);
            if ($size === false || $size <= $offsets[$fd]) continue;
            $fh = @fopen($files[$fd], 'rb');
            if ($fh === false) continue;
            fseek($fh, $offsets[$fd]);
            $chunk = fread($fh, $size - $offsets[$fd]);
            fclose($fh);
            if ($chunk === false || $chunk === '') continue;
            $offsets[$fd] = $size;
            $buffers[$fd] .= $chunk;
            while (($nl = strpos($buffers[$fd], "\n")) !== false) {
                $line = rtrim(substr($buffers[$fd], 0, $nl), "\r");
                $buffers[$fd] = substr($buffers[$fd], $nl + 1);
                if ($line === '') continue;
                $onLine($line, $fd === 2);
                if ($fd === 2) {
                    $stderrTail[] = $line;
                    if (count($stderrTail) > 20) array_shift($stderrTail);
                }
            }
        }
    };

    $startedAt = time();
    while (true) {
        $status = proc_get_status($process);
        $pump();

        if (!$status['running']) {
            usleep(150000); // one last pump in case anything was written right at exit
            $pump();
            break;
        }

        if ((time() - $startedAt) > $maxRuntimeSeconds) {
            $timedOut = true;
            @proc_terminate($process, 9);
            $pump();
            break;
        }

        usleep(200000); // poll every 200ms -- plenty fast for a human-readable log
    }

    foreach ([1, 2] as $fd) {
        if (trim($buffers[$fd]) !== '') {
            $onLine(rtrim($buffers[$fd]), $fd === 2);
        }
    }

    $exitCode = proc_close($process);
    @unlink($stdoutFile);
    @unlink($stderrFile);

    if ($timedOut) {
        throw new Exception("The extraction script was still running after " . round($maxRuntimeSeconds / 60) . " minute(s) and was stopped. This usually means a network call is hanging (check your internet connection / API keys) rather than the script being broken.");
    }

    return ['exitCode' => $exitCode, 'stderrTail' => $stderrTail];
}

/** Maps a line of the script's output to a log-console style (head/ok/warn/err/info). */
function classifyPyLine(string $line, bool $isStderr): string {
    if ($isStderr) {
        return (stripos($line, 'ERROR') !== false) ? 'err' : 'warn';
    }
    if (preg_match('/^\[\d+\/\d+\]/', $line)) return 'head';
    if (stripos($line, 'Falling back to Groq') !== false) return 'warn';
    if (strpos($line, '->') !== false) return 'ok';
    if (preg_match('/\bloaded\.$/', $line)) return 'ok';
    if (preg_match('/batch\(es\)\.$/', $line)) return 'ok';
    if (stripos($line, 'Done.') === 0) return 'head';
    if (preg_match('/^\s*-\s/', $line)) return 'warn';
    if (stripos($line, 'warning(s)') !== false) return 'warn';
    if (preg_match('/^(Loading reference data|Splitting PDF)/', $line)) return 'head';
    return 'info';
}

/** Best-effort "which exam does most of these rows belong to" for the results banner. */
function mostCommonExamCode(array $questions): ?string {
    $freq = [];
    foreach ($questions as $row) {
        foreach (explode(',', (string)($row['applicable_exam_codes'] ?? '')) as $code) {
            $code = trim($code);
            if ($code === '') continue;
            $freq[$code] = ($freq[$code] ?? 0) + 1;
        }
    }
    if (!$freq) return null;
    arsort($freq);
    return array_key_first($freq);
}

/* =======================================================================
 *  Page logic
 * ===================================================================== */

$pageTitle  = 'Generate Questions';
$pageCrumb  = 'AI Tools';
$activePage = 'generate';

$isGenerating = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_generate']));

/* Set up unbuffered/streaming output BEFORE any HTML (including the
 * shared header include) has been echoed -- calling header() after output
 * has already started throws "headers already sent" warnings. */
if ($isGenerating) {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    ob_implicit_flush(true);
    @ini_set('zlib.output_compression', '0');
    header('X-Accel-Buffering: no');
}

$extraScripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var drop = document.getElementById('uploadDrop');
    var input = document.getElementById('paperFile');
    var chip = document.getElementById('fileChip');
    var chipName = document.getElementById('fileChipName');

    if (input) {
        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                chipName.textContent = input.files[0].name;
                chip.classList.add('show');
            } else {
                chip.classList.remove('show');
            }
        });
    }
    if (drop) {
        ['dragover'].forEach(function (evt) {
            drop.addEventListener(evt, function (e) { e.preventDefault(); drop.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            drop.addEventListener(evt, function (e) { drop.classList.remove('dragover'); });
        });
    }

    var logBox = document.getElementById('logConsole');
    if (logBox) { logBox.scrollTop = logBox.scrollHeight; }
});
</script>
HTML;

include 'includes/header.php';
?>

<div class="gen-steps">
    <div class="gen-step<?= $isGenerating ? ' done' : '' ?>"><span class="num">1</span> Upload paper</div>
    <div class="gen-step<?= $isGenerating ? ' done' : '' ?>"><span class="num">2</span> Gemini extraction</div>
    <div class="gen-step<?= $isGenerating ? ' done' : '' ?>"><span class="num">3</span> Validate &amp; map</div>
    <div class="gen-step<?= $isGenerating ? ' done' : '' ?>"><span class="num">4</span> Dump into Qbank</div>
</div>

<?php if (isset($_GET['dumped'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <strong>Success!</strong> <?= (int)$_GET['dumped'] ?> question(s) have been dumped into the Qbank table
        <?php if (!empty($_GET['dupes'])): ?> (<?= (int)$_GET['dupes'] ?> flagged as duplicate)<?php endif; ?>
        <?php if (!empty($_GET['failed'])): ?>, <?= (int)$_GET['failed'] ?> row(s) were skipped due to an error<?php endif; ?>.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!$isGenerating): ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <strong>Upload a Question Paper</strong>
                    <div class="text-muted small mt-1">PDF or Word (.docx). Gemini reads the whole paper, works out which exam it's from, and classifies every question against your live subjects/subtopics. Groq is used automatically as a fallback if Gemini fails.</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="do_generate" value="1">

                        <label class="upload-drop mb-4" id="uploadDrop">
                            <input type="file" name="paper_file" id="paperFile" accept=".pdf,.docx" required>
                            <div class="icon-circle"><i class="bi bi-cloud-arrow-up-fill"></i></div>
                            <div class="fw-semibold">Click to browse or drag a file here</div>
                            <div class="text-muted small mt-1">.pdf or .docx &middot; up to 25 MB</div>
                            <div class="filename-chip" id="fileChip"><i class="bi bi-file-earmark-check-fill"></i> <span id="fileChipName"></span></div>
                        </label>

                        <button type="submit" class="btn btn-warning w-100 fw-bold py-2">
                            <i class="bi bi-stars me-1"></i> Generate Questions
                        </button>
                    </form>
                </div>
            </div>

            <div class="alert alert-secondary small mb-0">
                <strong>What happens next:</strong> the paper is sent to Gemini (or Groq, if Gemini fails) along with your current subjects, subtopics and exam list so every question comes back tagged with real ids. Answers, explanations and difficulty are AI-generated -- everything is inserted with <code>is_verified = 0</code> so you can review before publishing. The API keys and model names are configured on the server in <code>generate.php</code>, not entered here.
            </div>
        </div>
    </div>

<?php else: ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Processing Log</strong>
            <span class="badge bg-dark">Live</span>
        </div>
        <div class="card-body">
            <div class="log-console" id="logConsole"><script>
(function () {
    var box = document.getElementById('logConsole');
    if (!box) return;
    var pending = document.createElement('div');
    pending.className = 'log-line pending';
    pending.id = 'logPending';
    pending.innerHTML = '<span class="spinner-dot"></span><span class="msg">Working<span class="ellipsis"><span>.</span><span>.</span><span>.</span></span></span>';
    box.appendChild(pending);
    var mo = new MutationObserver(function () {
        if (pending.parentNode === box && box.lastElementChild !== pending) {
            box.appendChild(pending);
        }
        box.scrollTop = box.scrollHeight;
    });
    mo.observe(box, { childList: true });
    window.__qbankLogObserver = mo;
})();
</script>
<?php
function logLine(string $type, string $msg): void {
    echo '<div class="log-line ' . htmlspecialchars($type) . '"><span class="msg">' . htmlspecialchars($msg) . '</span></div>' . "\n";
    @flush();
}

// Defeat browser/proxy response buffering so the log genuinely streams
// line-by-line instead of appearing all at once when the page finishes.
echo '<!-- ' . str_repeat(' ', 4096) . " -->\n";
@flush();

$genResult = ['success' => false, 'error' => 'Unknown error.'];

try {
    if (!isset($_FILES['paper_file']) || $_FILES['paper_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file was uploaded, or the upload failed. Please choose a PDF or .docx file and try again.');
    }

    if (GEMINI_API_KEY === '' || GEMINI_API_KEY === 'PASTE_YOUR_GEMINI_API_KEY_HERE') {
        throw new Exception('No Gemini API key configured. Open generate.php and set GEMINI_API_KEY near the top of the file.');
    }

    $origName = $_FILES['paper_file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'docx'], true)) {
        throw new Exception("Unsupported file type \".$ext\". Please upload a .pdf or .docx file (legacy .doc is not supported -- please re-save it as .docx first).");
    }

    $maxBytes = 25 * 1024 * 1024;
    if ($_FILES['paper_file']['size'] > $maxBytes) {
        throw new Exception('File is larger than the 25 MB limit.');
    }

    $safeName = uniqid('paper_', true) . '.' . $ext;
    $storedPath = $UPLOAD_DIR . $safeName;
    if (!move_uploaded_file($_FILES['paper_file']['tmp_name'], $storedPath)) {
        throw new Exception('Could not save the uploaded file on the server. Check that the "uploads" folder is writable.');
    }

    $ref = loadReferenceData($pdo);
    $referenceContext = buildReferenceContext($ref);

    if ($ext === 'pdf') {

        /* -----------------------------------------------------------------
         * PDF -> hand off to scripts/pdf_to_qbank.py, exactly like running
         * it from a terminal: it splits the paper into page-batches and
         * calls Gemini (falling back to Groq per-batch if configured) once
         * per batch, streaming progress straight into this log.
         * --------------------------------------------------------------- */

        if (!is_file(PDF_TO_QBANK_SCRIPT)) {
            throw new Exception('scripts/pdf_to_qbank.py was not found next to generate.php. Make sure it was uploaded to the "scripts" folder.');
        }

        $refForJson = [
            'subjects' => $ref['subjects'],
            'subtopics' => array_map(fn($s) => [$s['subject_id'], $s['name']], $ref['subtopics']),
            'exams' => array_map(fn($e) => [
                'display_name' => $e['display_name'],
                'exam_name' => $e['exam_name'],
                'exam_level' => $e['exam_level'],
                'exam_category' => $e['exam_category'],
            ], $ref['exams']),
        ];
        $refJsonPath = $UPLOAD_DIR . 'ref_' . uniqid('', true) . '.json';
        file_put_contents($refJsonPath, json_encode($refForJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $cmd = [
            PYTHON_BIN, PDF_TO_QBANK_SCRIPT,
            '--pdf', $storedPath,
            '--api-key', GEMINI_API_KEY,
            '--model', GEMINI_MODEL,
            '--ref-json', $refJsonPath,
            '--pages-per-batch', (string)PAGES_PER_BATCH,
        ];
        if (GROQ_API_KEY !== '' && GROQ_API_KEY !== 'PASTE_YOUR_GROQ_API_KEY_HERE') {
            $cmd[] = '--groq-api-key';
            $cmd[] = GROQ_API_KEY;
            $cmd[] = '--groq-model';
            $cmd[] = GROQ_MODEL;
        }

        $warnCount = 0;
        $groqUsed = false;
        $jsonResultLine = null;
        $jsonMarker = 'QBANK_JSON_RESULT:';
        $runResult = runPdfToQbankScript($cmd, __DIR__, function (string $line, bool $isErr) use (&$warnCount, &$groqUsed, &$jsonResultLine, $jsonMarker) {
            // The script's very last stdout line is a structured hand-off of
            // the validated rows -- not a log line, so intercept it here
            // instead of printing/classifying it.
            if (!$isErr && strncmp($line, $jsonMarker, strlen($jsonMarker)) === 0) {
                $jsonResultLine = substr($line, strlen($jsonMarker));
                return;
            }
            $type = classifyPyLine($line, $isErr);
            logLine($type, $line);
            if ($type === 'warn') $warnCount++;
            if (stripos($line, 'Falling back to Groq') !== false) $groqUsed = true;
        }, SCRIPT_MAX_RUNTIME_SECONDS);

        @unlink($refJsonPath);
        @unlink($storedPath);

        if ($jsonResultLine === null) {
            $tail = implode(' | ', array_slice($runResult['stderrTail'], -5));
            throw new Exception('The extraction script did not return any results (exit code ' . $runResult['exitCode'] . ').' . ($tail ? ' Last error(s): ' . $tail : ''));
        }

        $pdfQuestions = json_decode($jsonResultLine, true);
        if (!is_array($pdfQuestions)) {
            throw new Exception('The extraction script returned malformed result data.');
        }
        if (empty($pdfQuestions)) {
            throw new Exception('The extraction script finished but no questions passed validation. See warnings above.');
        }

        $duplicateCount = 0;
        foreach ($pdfQuestions as $row) {
            if (!empty($row['dupe'])) $duplicateCount++;
        }

        $detectedExamCode = mostCommonExamCode($pdfQuestions);
        $detectedExamName = ($detectedExamCode && isset($ref['exams'][$detectedExamCode])) ? $ref['exams'][$detectedExamCode]['display_name'] : null;
        $usedProvider = 'Gemini (' . GEMINI_MODEL . ', batched)' . ($groqUsed ? ' + Groq fallback for some batches' : '');

        logLine('head', 'Staging ' . count($pdfQuestions) . ' question(s) for import into qbank...');
        $_SESSION['qbank_pending_import'] = $pdfQuestions;

        $genResult = [
            'success' => true,
            'count' => count($pdfQuestions),
            'dupes' => $duplicateCount,
            'warnings' => $warnCount,
            'detectedExamCode' => $detectedExamCode,
            'detectedExamName' => $detectedExamName,
            'provider' => $usedProvider,
        ];

    } else {

        /* -----------------------------------------------------------------
         * .docx -> single inline Gemini call (falls back to Groq on the
         * same extracted text if Gemini fails). No batching needed: docx
         * papers are already text and far smaller than a scanned PDF.
         * --------------------------------------------------------------- */

        logLine('head', 'Extracting text from the Word document...');
        $docText = extractDocxText($storedPath);
        if (trim($docText) === '') {
            throw new Exception('No readable text could be extracted from this .docx file.');
        }
        logLine('ok', '  ' . number_format(mb_strlen($docText)) . ' character(s) extracted.');

        $parts = [
            ['text' => "The following is the raw text extracted from a Word document question paper:\n\n" . $docText],
            ['text' => buildPrompt($referenceContext)],
        ];

        logLine('head', "Calling Gemini (" . GEMINI_MODEL . ") for extraction and classification...");

        $usedProvider = 'Gemini (' . GEMINI_MODEL . ')';
        try {
            $result = callGemini(GEMINI_API_KEY, GEMINI_MODEL, $parts, geminiResponseSchema());
            logLine('ok', '  -> structured response received.');
        } catch (Throwable $geminiError) {
            logLine('warn', 'Gemini failed: ' . $geminiError->getMessage());

            if (GROQ_API_KEY === '' || GROQ_API_KEY === 'PASTE_YOUR_GROQ_API_KEY_HERE') {
                throw new Exception('Gemini failed and no Groq fallback API key is configured (set GROQ_API_KEY in generate.php). Original Gemini error: ' . $geminiError->getMessage());
            }

            logLine('head', 'Falling back to Groq (' . GROQ_MODEL . ')...');
            $groqPrompt = buildPrompt($referenceContext) . "\n\nDocument text:\n\n" . $docText;
            $result = callGroq(GROQ_API_KEY, GROQ_MODEL, $groqPrompt, geminiResponseSchema());
            logLine('ok', 'Received a structured response from Groq (fallback).');
            $usedProvider = 'Groq (' . GROQ_MODEL . ', fallback)';
        }

        $detectedExamCode = $result['detected_exam_code'] ?? null;
        if ($detectedExamCode && isset($ref['exams'][$detectedExamCode])) {
            $reason = $result['detected_exam_reason'] ?? '';
            logLine('ok', "Detected source exam: {$ref['exams'][$detectedExamCode]['display_name']} ($detectedExamCode)" . ($reason ? " -- $reason" : ''));
        } else {
            logLine('warn', 'Could not confidently identify a single source exam from the valid list; falling back to per-question classification only.');
            $detectedExamCode = null;
        }

        $questions = $result['questions'] ?? [];
        logLine('info', count($questions) . ' question(s) extracted from the document.');

        if (empty($questions)) {
            throw new Exception('No questions could be extracted from this document.');
        }

        logLine('head', 'Validating and mapping subject / subtopic / exam ids...');
        $warnings = [];
        $validQuestions = [];
        foreach ($questions as $q) {
            $fixed = validateAndFix($q, $ref, $detectedExamCode, $warnings);
            if ($fixed) {
                $validQuestions[] = $fixed;
            }
        }
        foreach ($warnings as $w) {
            logLine('warn', $w);
        }
        logLine('ok', count($validQuestions) . ' question(s) passed validation.');

        if (empty($validQuestions)) {
            throw new Exception('All extracted questions failed validation. See warnings above.');
        }

        $dupeCount = flagDuplicatesInPlace($validQuestions);

        logLine('head', 'Staging ' . count($validQuestions) . " question(s) for import into qbank ($dupeCount flagged as duplicate)...");
        @unlink($storedPath);

        $_SESSION['qbank_pending_import'] = $validQuestions;

        $genResult = [
            'success' => true,
            'count' => count($validQuestions),
            'dupes' => $dupeCount,
            'warnings' => count($warnings),
            'detectedExamCode' => $detectedExamCode,
            'detectedExamName' => ($detectedExamCode && isset($ref['exams'][$detectedExamCode])) ? $ref['exams'][$detectedExamCode]['display_name'] : null,
            'provider' => $usedProvider,
        ];
    }
} catch (Throwable $e) {
    logLine('err', 'ERROR: ' . $e->getMessage());
    $genResult = ['success' => false, 'error' => $e->getMessage()];
    if (!empty($storedPath) && is_file($storedPath)) {
        @unlink($storedPath);
    }
}
?>
<script>
(function () {
    if (window.__qbankLogObserver) { window.__qbankLogObserver.disconnect(); }
    var pending = document.getElementById('logPending');
    if (pending) { pending.remove(); }
})();
</script>
            </div>
        </div>
    </div>

    <?php if ($genResult['success']): ?>
        <div class="result-banner mb-4">
            <div>
                <?php if (!empty($genResult['detectedExamName'])): ?>
                    <div class="detected-exam-chip"><i class="bi bi-mortarboard-fill"></i> Detected exam: <?= htmlspecialchars($genResult['detectedExamName']) ?> (<?= htmlspecialchars($genResult['detectedExamCode']) ?>)</div>
                    <br>
                <?php endif; ?>
                <div class="figure"><?= (int)$genResult['count'] ?></div>
                <div class="figure-label"><?= (int)$genResult['count'] ?> question(s) written &middot; <?= (int)$genResult['dupes'] ?> flagged as duplicate &middot; <?= (int)$genResult['warnings'] ?> warning(s)</div>
                <?php if (!empty($genResult['provider'])): ?>
                    <div class="text-muted small mt-1">Generated using <?= htmlspecialchars($genResult['provider']) ?></div>
                <?php endif; ?>
            </div>
            <form method="POST" action="dump_into_qbank.php" class="m-0"
                  onsubmit="return confirm('Dump all <?= (int)$genResult['count'] ?> generated question(s) into Qbank? This will insert them into the qbank table.');">
                <button type="submit" class="btn btn-success btn-lg px-4">
                    <i class="bi bi-database-fill-add me-2"></i>Dump into Qbank
                </button>
            </form>
        </div>
        <div class="alert alert-warning small">
            <strong>Review before publishing:</strong> every generated row has <code>is_verified = 0</code>. You can dump the questions into Qbank now; they will remain unverified until you review them.
        </div>
    <?php else: ?>
        <div class="alert alert-danger"><strong>Generation failed:</strong> <?= htmlspecialchars($genResult['error']) ?></div>
    <?php endif; ?>

    <a href="generate.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i> Generate from another file</a>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
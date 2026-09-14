<?php
$host = getenv('DB_HOST') ?: 'localhost:3306';
$dbname = getenv('DB_NAME') ?: 'question_generator';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

$sslCa = __DIR__ . '/ssl/DigiCertGlobalRootG2.crt.pem';

try {
    $dsn = "mysql:host={$host};port=3306;dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,

        // Azure MySQL SSL/TLS
        PDO::MYSQL_ATTR_SSL_CA => $sslCa,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);

} catch (PDOException $e) {
    error_log("Database Connection Failed - db.php: " . $e->getMessage());
    die("Database Connection Failed. Please check error logs.");
}

if (!function_exists('contentHashFor')) {
    function contentHashFor(string $text): string {
        return hash('sha256', mb_strtolower(trim($text)));
    }
}

if (!function_exists('insertQuestionsIntoQbank')) {
    /**
     * Inserts validated question rows directly into `qbank` via one
     * parameterized prepared statement (bound values) executed once per row.
     * There is no hand-built SQL text anywhere in this path, so it can never
     * hit the "syntax error near ...<some word from the question text>..."
     * class of bug a regex-based SQL-file importer is prone to (a literal
     * semicolon or quote inside question_text/explanation is just data to a
     * prepared statement, never a statement boundary).
     *
     * Each row is expected to have: subject_id, subtopic_id,
     * applicable_exam_codes, difficulty, question_text, option1..4,
     * correct_answer, explanation (as produced by validateAndFix() /
     * validate_and_fix()). content_hash/dupe are used if already present
     * (the PDF pipeline pre-computes them); otherwise they're computed here
     * against the hashes already seen earlier in this same call.
     *
     * A duplicate content_hash (within this batch, or already sitting in
     * qbank from a previous dump) is UPSERTed rather than allowed to error,
     * so re-dumping the same paper twice never fails.
     */
    function insertQuestionsIntoQbank(PDO $pdo, array $questions): array {
        $columns = [
            'subject_id', 'subtopic_id', 'applicable_exam_codes', 'difficulty',
            'question_type', 'question_text', 'option1', 'option2', 'option3',
            'option4', 'correct_answer', 'explanation', 'content_hash',
            'generated_by_ai', 'is_verified', 'is_active', 'dupe',
        ];
        $columnList = implode(', ', array_map(fn($c) => "`$c`", $columns));
        $placeholders = implode(', ', array_map(fn($c) => ":$c", $columns));

        $stmt = $pdo->prepare(
            "INSERT INTO `qbank` ($columnList) VALUES ($placeholders)
             ON DUPLICATE KEY UPDATE `dupe` = 1"
        );

        $seenHashes = [];
        $inserted = 0;
        $duplicates = 0;
        $failed = 0;
        $failMessages = [];

        $pdo->beginTransaction();
        try {
            foreach ($questions as $q) {
                $hash = (!empty($q['content_hash']))
                    ? $q['content_hash']
                    : contentHashFor((string)($q['question_text'] ?? ''));

                if (isset($seenHashes[$hash])) {
                    $dupeFlag = 1;
                } else {
                    $dupeFlag = isset($q['dupe']) ? (int)$q['dupe'] : 0;
                    $seenHashes[$hash] = true;
                }
                if ($dupeFlag) {
                    $duplicates++;
                }

                try {
                    $stmt->execute([
                        ':subject_id'            => (int)($q['subject_id'] ?? 0),
                        ':subtopic_id'           => (int)($q['subtopic_id'] ?? 0),
                        ':applicable_exam_codes' => (string)($q['applicable_exam_codes'] ?? ''),
                        ':difficulty'            => (int)($q['difficulty'] ?? 2),
                        ':question_type'         => 'MCQ',
                        ':question_text'         => (string)($q['question_text'] ?? ''),
                        ':option1'               => (string)($q['option1'] ?? ''),
                        ':option2'               => (string)($q['option2'] ?? ''),
                        ':option3'               => (string)($q['option3'] ?? ''),
                        ':option4'               => (string)($q['option4'] ?? ''),
                        ':correct_answer'        => (int)($q['correct_answer'] ?? 0),
                        ':explanation'           => $q['explanation'] ?? null,
                        ':content_hash'          => $hash,
                        ':generated_by_ai'       => 1,
                        ':is_verified'           => 0,
                        ':is_active'             => 1,
                        ':dupe'                  => $dupeFlag,
                    ]);
                    $inserted++;
                } catch (Throwable $rowError) {
                    $failed++;
                    $failMessages[] = $rowError->getMessage();
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'inserted'     => $inserted,
            'duplicates'   => $duplicates,
            'failed'       => $failed,
            'failMessages' => $failMessages,
        ];
    }
}
?>
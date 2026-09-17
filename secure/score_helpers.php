<?php
// secure/score_helpers.php

function loadScoringOptionsByQuestion(mysqli $conn): array {
    $map = []; // [question_id][option_id] => ['text'=>..., 'points'=>...]

    $res = $conn->query("
        SELECT
            id,
            question_id,
            option_text,
            COALESCE(NULLIF(points,''), option_points) AS pts
        FROM scoring_options
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $qid = (int)$row['question_id'];
            $oid = (int)$row['id'];
            $map[$qid][$oid] = [
                'text'   => (string)$row['option_text'],
                'points' => (int)$row['pts'],
            ];
        }
    }

    return $map;
}

/**
 * Translate a posted value for a given question into safe storage.
 * - If posted value matches an option_id for that question => use option text + points
 * - Else if numeric => treat as points
 * - Else => treat as free text with 0 points
 */
function normalizeResponse(int $questionId, $postedValue, array $optionsByQuestion): array {
    $raw = trim((string)$postedValue);

    $responseText = $raw;
    $points = 0;

    if ($raw !== '' && ctype_digit($raw)) {
        $oid = (int)$raw;

        // Only accept as option_id if it belongs to THIS question
        if (isset($optionsByQuestion[$questionId][$oid])) {
            $opt = $optionsByQuestion[$questionId][$oid];
            $responseText = $opt['text'];
            $points = (int)$opt['points'];
        } else {
            // It's a numeric points value (not an option_id)
            $points = (int)$raw;
        }
    } else {
        // Non-numeric free text => keep text, 0 points
        $points = 0;
    }

    return [$responseText, $points];
}

<?php
declare(strict_types=1);

final class FitService {
  private const RULE_BLOCKS = [
    'objectives',
    'planning',
    'volume',
    'effort',
    'progression',
    'exercise_selection',
    'adaptation',
    'pain_policy',
    'evidence_policy',
  ];

  public function __construct(private readonly PDO $pdo) {}

  public function initializeUser(int $userId): void {
    $this->assertUserExists($userId);
    $this->pdo->prepare('INSERT IGNORE INTO fit_user_profile(user_id) VALUES(?)')->execute([$userId]);

    $statement = $this->pdo->prepare(
      "INSERT IGNORE INTO fit_rule_block(user_id, code, status) VALUES(?, ?, 'incomplete')"
    );
    foreach (self::RULE_BLOCKS as $code) {
      $statement->execute([$userId, $code]);
    }
  }

  public function getConfigurationStatus(int $userId): array {
    $this->initializeUser($userId);
    $statement = $this->pdo->prepare(
      'SELECT rb.code, rb.status, MAX(rbv.version_number) AS version_number
       FROM fit_rule_block rb
       LEFT JOIN fit_rule_block_version rbv ON rbv.rule_block_id = rb.id
       WHERE rb.user_id = ?
       GROUP BY rb.id, rb.code, rb.status
       ORDER BY rb.code'
    );
    $statement->execute([$userId]);
    $blocks = $statement->fetchAll();

    return [
      'profile_configured' => $this->profileIsConfigured($userId),
      'blocks' => array_map(static fn(array $block): array => [
        'code' => $block['code'],
        'status' => $block['status'],
        'version_number' => $block['version_number'] === null ? null : (int) $block['version_number'],
      ], $blocks),
    ];
  }

  /**
   * Stores only structured profile facts. The caller is responsible for asking
   * the user; this service deliberately does not infer missing values.
   */
  public function saveProfile(int $userId, array $profileData, ?string $timezone = null): array {
    $this->initializeUser($userId);
    $timezone = $timezone === null ? null : trim($timezone);
    if ($timezone !== null && ($timezone === '' || strlen($timezone) > 64)) {
      throw new FitValidationException('INVALID_TIMEZONE', 'timezone is invalid');
    }

    $statement = $this->pdo->prepare(
      'UPDATE fit_user_profile SET profile_data = ?, timezone = COALESCE(?, timezone) WHERE user_id = ?'
    );
    $statement->execute([$this->encodeJson($profileData), $timezone, $userId]);
    return $this->getSessionContext($userId)['profile'];
  }

  /**
   * Appends an immutable version rather than overwriting coaching rules.
   */
  public function saveRuleBlock(int $userId, string $code, array $configurationData, string $status = 'complete'): array {
    if (!in_array($code, self::RULE_BLOCKS, true)) {
      throw new FitValidationException('UNKNOWN_RULE_BLOCK', 'Unknown rule block');
    }
    if (!in_array($status, ['incomplete', 'complete'], true)) {
      throw new FitValidationException('INVALID_RULE_BLOCK_STATUS', 'Invalid rule block status');
    }

    $this->pdo->beginTransaction();
    try {
      $this->lockUser($userId);
      $this->initializeUser($userId);
      $blockStatement = $this->pdo->prepare('SELECT id FROM fit_rule_block WHERE user_id = ? AND code = ? FOR UPDATE');
      $blockStatement->execute([$userId, $code]);
      $blockId = $blockStatement->fetchColumn();
      if ($blockId === false) {
        throw new RuntimeException('Fit rule block initialization failed');
      }
      $versionStatement = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM fit_rule_block_version WHERE rule_block_id = ? FOR UPDATE');
      $versionStatement->execute([(int) $blockId]);
      $versionNumber = (int) $versionStatement->fetchColumn();
      $this->pdo->prepare(
        'INSERT INTO fit_rule_block_version(rule_block_id, version_number, configuration_data, changed_by_user_id) VALUES(?, ?, ?, ?)'
      )->execute([(int) $blockId, $versionNumber, $this->encodeJson($configurationData), $userId]);
      $this->pdo->prepare('UPDATE fit_rule_block SET status = ? WHERE id = ?')->execute([$status, (int) $blockId]);
      $this->pdo->commit();
      return ['code' => $code, 'status' => $status, 'version_number' => $versionNumber];
    } catch (Throwable $exception) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $exception;
    }
  }

  public function getSessionContext(int $userId, ?DateTimeImmutable $now = null): array {
    $this->initializeUser($userId);
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    $today = $now->format('Y-m-d');

    $profileStatement = $this->pdo->prepare('SELECT profile_data, timezone FROM fit_user_profile WHERE user_id = ?');
    $profileStatement->execute([$userId]);
    $profile = $profileStatement->fetch() ?: ['profile_data' => null, 'timezone' => 'Europe/Paris'];

    $blocksStatement = $this->pdo->prepare(
      'SELECT rb.code, rb.status, rbv.version_number, rbv.configuration_data
       FROM fit_rule_block rb
       LEFT JOIN fit_rule_block_version rbv ON rbv.id = (
         SELECT inner_version.id FROM fit_rule_block_version inner_version
         WHERE inner_version.rule_block_id = rb.id ORDER BY inner_version.version_number DESC LIMIT 1
       )
       WHERE rb.user_id = ? ORDER BY rb.code'
    );
    $blocksStatement->execute([$userId]);

    $equipmentStatement = $this->pdo->prepare(
      'SELECT id, code, name, equipment_type, configuration_data
       FROM fit_equipment WHERE user_id = ? AND is_active = 1 ORDER BY name'
    );
    $equipmentStatement->execute([$userId]);

    $contextsStatement = $this->pdo->prepare(
      "SELECT id, starts_on, ends_on, location_name, constraints_data, duration_limit_minutes, temporary_objective, exit_rule
       FROM fit_availability_context
       WHERE user_id = ? AND status = 'active' AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)
       ORDER BY starts_on DESC, id DESC"
    );
    $contextsStatement->execute([$userId, $today, $today]);

    $painStatement = $this->pdo->prepare(
      "SELECT id, started_on, location_label, intensity, affected_movements, comment
       FROM fit_pain_event
       WHERE user_id = ? AND status = 'active' AND started_on <= ? AND (ended_on IS NULL OR ended_on >= ?)
       ORDER BY started_on DESC, id DESC"
    );
    $painStatement->execute([$userId, $today, $today]);

    $programStatement = $this->pdo->prepare(
      "SELECT p.id AS program_id, p.name AS program_name, pr.id AS revision_id, pr.revision_number, pr.notes
       FROM fit_program p
       JOIN fit_program_revision pr ON pr.program_id = p.id
       WHERE p.user_id = ? AND p.status = 'active' AND pr.status = 'published'
       ORDER BY pr.revision_number DESC LIMIT 1"
    );
    $programStatement->execute([$userId]);
    $program = $programStatement->fetch() ?: null;

    $recentStatement = $this->pdo->prepare(
      "SELECT id, status, started_at, ended_at, declared_duration_seconds, energy_before, energy_after
       FROM fit_session WHERE user_id = ? ORDER BY started_at DESC, id DESC LIMIT 10"
    );
    $recentStatement->execute([$userId]);

    $activeDraftStatement = $this->pdo->prepare(
      'SELECT d.id, d.started_at, d.last_checkpoint_at FROM fit_active_session active
       JOIN fit_session_draft d ON d.id = active.draft_id WHERE active.user_id = ?'
    );
    $activeDraftStatement->execute([$userId]);

    return [
      'profile' => [
        'data' => $this->decodeNullableJson($profile['profile_data']),
        'timezone' => $profile['timezone'],
      ],
      'configuration' => array_map(fn(array $row): array => [
        'code' => $row['code'],
        'status' => $row['status'],
        'version_number' => $row['version_number'] === null ? null : (int) $row['version_number'],
        'data' => $this->decodeNullableJson($row['configuration_data']),
      ], $blocksStatement->fetchAll()),
      'equipment' => array_map(fn(array $row): array => [
        'id' => (int) $row['id'],
        'code' => $row['code'],
        'name' => $row['name'],
        'type' => $row['equipment_type'],
        'configuration' => $this->decodeNullableJson($row['configuration_data']),
      ], $equipmentStatement->fetchAll()),
      'availability_contexts' => array_map(fn(array $row): array => [
        'id' => (int) $row['id'],
        'starts_on' => $row['starts_on'],
        'ends_on' => $row['ends_on'],
        'location_name' => $row['location_name'],
        'constraints' => $this->decodeNullableJson($row['constraints_data']),
        'duration_limit_minutes' => $row['duration_limit_minutes'] === null ? null : (int) $row['duration_limit_minutes'],
        'temporary_objective' => $row['temporary_objective'],
        'exit_rule' => $row['exit_rule'],
      ], $contextsStatement->fetchAll()),
      'active_pain' => array_map(fn(array $row): array => [
        'id' => (int) $row['id'],
        'started_on' => $row['started_on'],
        'location' => $row['location_label'],
        'intensity' => $row['intensity'] === null ? null : (int) $row['intensity'],
        'affected_movements' => $this->decodeNullableJson($row['affected_movements']),
        'comment' => $row['comment'],
      ], $painStatement->fetchAll()),
      'program' => $program === null ? null : [
        'id' => (int) $program['program_id'],
        'name' => $program['program_name'],
        'revision_id' => (int) $program['revision_id'],
        'revision_number' => (int) $program['revision_number'],
        'notes' => $program['notes'],
      ],
      'recent_sessions' => $recentStatement->fetchAll(),
      'active_draft' => $activeDraftStatement->fetch() ?: null,
    ];
  }

  public function openOrResumeDraft(int $userId): array {
    $this->pdo->beginTransaction();
    try {
      $this->lockUser($userId);
      $this->initializeUser($userId);

      $activeStatement = $this->pdo->prepare(
        'SELECT d.id, d.started_at, d.last_checkpoint_at, d.context_snapshot
         FROM fit_active_session active JOIN fit_session_draft d ON d.id = active.draft_id
         WHERE active.user_id = ? FOR UPDATE'
      );
      $activeStatement->execute([$userId]);
      $active = $activeStatement->fetch();
      if ($active) {
        $this->pdo->commit();
        return [
          'draft_id' => (int) $active['id'],
          'resumed' => true,
          'started_at' => $active['started_at'],
          'last_checkpoint_at' => $active['last_checkpoint_at'],
          'context_snapshot' => $this->decodeJson($active['context_snapshot']),
        ];
      }

      $context = $this->getSessionContext($userId);
      $programRevisionId = $context['program']['revision_id'] ?? null;
      $insertDraft = $this->pdo->prepare(
        "INSERT INTO fit_session_draft(user_id, status, program_revision_id, started_at, context_snapshot)
         VALUES(?, 'active', ?, NOW(), ?)"
      );
      $insertDraft->execute([$userId, $programRevisionId, $this->encodeJson($context)]);
      $draftId = (int) $this->pdo->lastInsertId();
      $this->pdo->prepare('INSERT INTO fit_active_session(user_id, draft_id) VALUES(?, ?)')->execute([$userId, $draftId]);
      $this->pdo->commit();

      return [
        'draft_id' => $draftId,
        'resumed' => false,
        'started_at' => null,
        'last_checkpoint_at' => null,
        'context_snapshot' => $context,
      ];
    } catch (Throwable $exception) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $exception;
    }
  }

  public function checkpointDraft(int $userId, int $draftId, array $payload): array {
    $exercises = $payload['exercises'] ?? null;
    if (!is_array($exercises)) {
      throw new FitValidationException('INVALID_CHECKPOINT', 'exercises must be an array');
    }

    $normalized = $this->normalizeExercises($userId, $exercises);
    $this->pdo->beginTransaction();
    try {
      $this->lockDraft($userId, $draftId);
      $this->pdo->prepare('DELETE FROM fit_session_draft_exercise WHERE draft_id = ?')->execute([$draftId]);
      $exerciseInsert = $this->pdo->prepare(
        'INSERT INTO fit_session_draft_exercise(draft_id, reference_exercise_id, user_exercise_id, sequence_number, planned_data, was_omitted, omission_reason, note)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $setInsert = $this->pdo->prepare(
        'INSERT INTO fit_session_draft_set(draft_exercise_id, set_number, result_type, repetitions, duration_seconds, load_value, load_unit, machine_setting, rir, rpe, technique_score, pain_score, note, performed_at)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
      );

      foreach ($normalized as $exercise) {
        $exerciseInsert->execute([
          $draftId,
          $exercise['reference_exercise_id'],
          $exercise['user_exercise_id'],
          $exercise['sequence_number'],
          $this->encodeNullableJson($exercise['planned_data']),
          $exercise['was_omitted'] ? 1 : 0,
          $exercise['omission_reason'],
          $exercise['note'],
        ]);
        $draftExerciseId = (int) $this->pdo->lastInsertId();
        foreach ($exercise['sets'] as $set) {
          $setInsert->execute([
            $draftExerciseId,
            $set['set_number'],
            $set['result_type'],
            $set['repetitions'],
            $set['duration_seconds'],
            $set['load_value'],
            $set['load_unit'],
            $set['machine_setting'],
            $set['rir'],
            $set['rpe'],
            $set['technique_score'],
            $set['pain_score'],
            $set['note'],
            $set['performed_at'],
          ]);
        }
      }

      $this->pdo->prepare('UPDATE fit_session_draft SET last_checkpoint_at = NOW(), user_note = ? WHERE id = ?')
        ->execute([$this->nullableString($payload['note'] ?? null), $draftId]);
      $this->pdo->commit();
      return $this->getDraft($userId, $draftId);
    } catch (Throwable $exception) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $exception;
    }
  }

  public function closeDraft(int $userId, int $draftId, array $payload): array {
    $status = $payload['status'] ?? 'completed';
    if (!in_array($status, ['completed', 'partial'], true)) {
      throw new FitValidationException('INVALID_SESSION_STATUS', 'status must be completed or partial');
    }

    $this->pdo->beginTransaction();
    try {
      $draft = $this->lockDraft($userId, $draftId);
      $exerciseStatement = $this->pdo->prepare('SELECT * FROM fit_session_draft_exercise WHERE draft_id = ? ORDER BY sequence_number');
      $exerciseStatement->execute([$draftId]);
      $draftExercises = $exerciseStatement->fetchAll();
      $setCountStatement = $this->pdo->prepare(
        'SELECT COUNT(*) FROM fit_session_draft_set sets
         JOIN fit_session_draft_exercise exercise ON exercise.id = sets.draft_exercise_id
         WHERE exercise.draft_id = ?'
      );
      $setCountStatement->execute([$draftId]);
      if ((int) $setCountStatement->fetchColumn() === 0) {
        throw new FitValidationException('EMPTY_DRAFT', 'A session without performed sets must be abandoned, not closed');
      }

      $endAt = $this->nullableDateTime($payload['ended_at'] ?? null) ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
      $sessionInsert = $this->pdo->prepare(
        'INSERT INTO fit_session(user_id, draft_id, status, program_revision_id, started_at, ended_at, declared_duration_seconds, energy_before, energy_after, context_snapshot, note)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $sessionInsert->execute([
        $userId,
        $draftId,
        $status,
        $draft['program_revision_id'],
        $draft['started_at'],
        $endAt,
        $this->nullablePositiveInt($payload['declared_duration_seconds'] ?? null, 'declared_duration_seconds'),
        $this->nullableScore($payload['energy_before'] ?? null, 0, 10, 'energy_before'),
        $this->nullableScore($payload['energy_after'] ?? null, 0, 10, 'energy_after'),
        $draft['context_snapshot'],
        $this->nullableString($payload['note'] ?? $draft['user_note']),
      ]);
      $sessionId = (int) $this->pdo->lastInsertId();

      $sessionExerciseInsert = $this->pdo->prepare(
        'INSERT INTO fit_session_exercise(session_id, reference_exercise_id, user_exercise_id, sequence_number, planned_data, was_omitted, omission_reason, note)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $setStatement = $this->pdo->prepare('SELECT * FROM fit_session_draft_set WHERE draft_exercise_id = ? ORDER BY set_number');
      $sessionSetInsert = $this->pdo->prepare(
        'INSERT INTO fit_set(session_exercise_id, set_number, result_type, repetitions, duration_seconds, load_value, load_unit, machine_setting, rir, rpe, technique_score, pain_score, note, performed_at)
         VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
      );
      foreach ($draftExercises as $exercise) {
        $sessionExerciseInsert->execute([
          $sessionId,
          $exercise['reference_exercise_id'],
          $exercise['user_exercise_id'],
          $exercise['sequence_number'],
          $exercise['planned_data'],
          $exercise['was_omitted'],
          $exercise['omission_reason'],
          $exercise['note'],
        ]);
        $sessionExerciseId = (int) $this->pdo->lastInsertId();
        $setStatement->execute([(int) $exercise['id']]);
        foreach ($setStatement->fetchAll() as $set) {
          $sessionSetInsert->execute([
            $sessionExerciseId,
            $set['set_number'],
            $set['result_type'],
            $set['repetitions'],
            $set['duration_seconds'],
            $set['load_value'],
            $set['load_unit'],
            $set['machine_setting'],
            $set['rir'],
            $set['rpe'],
            $set['technique_score'],
            $set['pain_score'],
            $set['note'],
            $set['performed_at'],
          ]);
        }
      }

      $this->pdo->prepare("UPDATE fit_session_draft SET status = 'closed', last_checkpoint_at = NOW() WHERE id = ?")->execute([$draftId]);
      $this->pdo->prepare('DELETE FROM fit_active_session WHERE user_id = ? AND draft_id = ?')->execute([$userId, $draftId]);
      $this->pdo->commit();
      return ['session_id' => $sessionId, 'status' => $status];
    } catch (Throwable $exception) {
      if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
      }
      throw $exception;
    }
  }

  public function getDraft(int $userId, int $draftId): array {
    $draft = $this->findDraft($userId, $draftId);
    if ($draft === null) {
      throw new FitValidationException('DRAFT_NOT_FOUND', 'Draft session not found');
    }
    $exerciseStatement = $this->pdo->prepare('SELECT * FROM fit_session_draft_exercise WHERE draft_id = ? ORDER BY sequence_number');
    $exerciseStatement->execute([$draftId]);
    $setStatement = $this->pdo->prepare('SELECT * FROM fit_session_draft_set WHERE draft_exercise_id = ? ORDER BY set_number');
    $exercises = [];
    foreach ($exerciseStatement->fetchAll() as $exercise) {
      $setStatement->execute([(int) $exercise['id']]);
      $exercise['planned_data'] = $this->decodeNullableJson($exercise['planned_data']);
      $exercise['sets'] = $setStatement->fetchAll();
      $exercises[] = $exercise;
    }
    $draft['context_snapshot'] = $this->decodeJson($draft['context_snapshot']);
    $draft['exercises'] = $exercises;
    return $draft;
  }

  private function normalizeExercises(int $userId, array $exercises): array {
    $normalized = [];
    $sequences = [];
    foreach ($exercises as $input) {
      if (!is_array($input)) {
        throw new FitValidationException('INVALID_EXERCISE', 'Each exercise must be an object');
      }
      $sequence = $this->requiredPositiveInt($input['sequence_number'] ?? null, 'sequence_number');
      if (isset($sequences[$sequence])) {
        throw new FitValidationException('DUPLICATE_EXERCISE_SEQUENCE', 'Exercise sequence numbers must be unique');
      }
      $sequences[$sequence] = true;
      $referenceId = $this->nullablePositiveInt($input['reference_exercise_id'] ?? null, 'reference_exercise_id');
      $userExerciseId = $this->nullablePositiveInt($input['user_exercise_id'] ?? null, 'user_exercise_id');
      if (($referenceId === null) === ($userExerciseId === null)) {
        throw new FitValidationException('INVALID_EXERCISE_REFERENCE', 'Exactly one exercise reference is required');
      }
      $this->assertExerciseOwnership($userId, $referenceId, $userExerciseId);
      $omitted = !empty($input['was_omitted']);
      $omissionReason = $this->nullableString($input['omission_reason'] ?? null);
      $sets = $input['sets'] ?? [];
      if (!is_array($sets)) {
        throw new FitValidationException('INVALID_SET_LIST', 'sets must be an array');
      }
      if ($omitted && $omissionReason === null) {
        throw new FitValidationException('OMISSION_REASON_REQUIRED', 'An omitted exercise requires a reason');
      }
      if ($omitted && count($sets) > 0) {
        throw new FitValidationException('OMITTED_EXERCISE_HAS_SETS', 'An omitted exercise cannot contain performed sets');
      }
      $normalized[] = [
        'sequence_number' => $sequence,
        'reference_exercise_id' => $referenceId,
        'user_exercise_id' => $userExerciseId,
        'planned_data' => $input['planned_data'] ?? null,
        'was_omitted' => $omitted,
        'omission_reason' => $omissionReason,
        'note' => $this->nullableString($input['note'] ?? null),
        'sets' => $this->normalizeSets($sets),
      ];
    }
    usort($normalized, static fn(array $left, array $right): int => $left['sequence_number'] <=> $right['sequence_number']);
    return $normalized;
  }

  private function normalizeSets(array $sets): array {
    $normalized = [];
    $numbers = [];
    foreach ($sets as $input) {
      if (!is_array($input)) {
        throw new FitValidationException('INVALID_SET', 'Each set must be an object');
      }
      $number = $this->requiredPositiveInt($input['set_number'] ?? null, 'set_number');
      if (isset($numbers[$number])) {
        throw new FitValidationException('DUPLICATE_SET_NUMBER', 'Set numbers must be unique');
      }
      $numbers[$number] = true;
      $type = $input['result_type'] ?? null;
      if (!is_string($type) || !in_array($type, ['dynamic', 'cardio', 'isometric', 'other'], true)) {
        throw new FitValidationException('INVALID_RESULT_TYPE', 'result_type is invalid');
      }
      $repetitions = $this->nullablePositiveInt($input['repetitions'] ?? null, 'repetitions');
      $duration = $this->nullablePositiveInt($input['duration_seconds'] ?? null, 'duration_seconds');
      if ($type === 'dynamic' && $repetitions === null) {
        throw new FitValidationException('REPETITIONS_REQUIRED', 'Dynamic sets require repetitions');
      }
      if (in_array($type, ['cardio', 'isometric'], true) && $duration === null) {
        throw new FitValidationException('DURATION_REQUIRED', 'Cardio and isometric sets require a duration');
      }
      $normalized[] = [
        'set_number' => $number,
        'result_type' => $type,
        'repetitions' => $repetitions,
        'duration_seconds' => $duration,
        'load_value' => $this->nullableDecimal($input['load_value'] ?? null, 0, null, 'load_value'),
        'load_unit' => $this->nullableString($input['load_unit'] ?? null, 32),
        'machine_setting' => $this->nullableDecimal($input['machine_setting'] ?? null, 0, null, 'machine_setting'),
        'rir' => $this->nullableDecimal($input['rir'] ?? null, 0, 5, 'rir'),
        'rpe' => $this->nullableDecimal($input['rpe'] ?? null, 0, 10, 'rpe'),
        'technique_score' => $this->nullableScore($input['technique_score'] ?? null, 1, 5, 'technique_score'),
        'pain_score' => $this->nullableScore($input['pain_score'] ?? null, 0, 10, 'pain_score'),
        'note' => $this->nullableString($input['note'] ?? null),
        'performed_at' => $this->nullableDateTime($input['performed_at'] ?? null),
      ];
    }
    usort($normalized, static fn(array $left, array $right): int => $left['set_number'] <=> $right['set_number']);
    return $normalized;
  }

  private function assertExerciseOwnership(int $userId, ?int $referenceId, ?int $userExerciseId): void {
    if ($referenceId !== null) {
      $statement = $this->pdo->prepare('SELECT id FROM fit_exercise_reference WHERE id = ? AND is_active = 1');
      $statement->execute([$referenceId]);
      if (!$statement->fetchColumn()) {
        throw new FitValidationException('EXERCISE_NOT_FOUND', 'Referenced exercise is not available');
      }
      return;
    }
    $statement = $this->pdo->prepare('SELECT id FROM fit_user_exercise WHERE id = ? AND user_id = ? AND is_active = 1');
    $statement->execute([$userExerciseId, $userId]);
    if (!$statement->fetchColumn()) {
      throw new FitValidationException('EXERCISE_NOT_FOUND', 'User exercise is not available');
    }
  }

  private function lockUser(int $userId): void {
    $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $statement->execute([$userId]);
    if (!$statement->fetchColumn()) {
      throw new FitValidationException('USER_NOT_FOUND', 'User not found');
    }
  }

  private function lockDraft(int $userId, int $draftId): array {
    $statement = $this->pdo->prepare(
      "SELECT d.* FROM fit_session_draft d JOIN fit_active_session active ON active.draft_id = d.id
       WHERE d.id = ? AND d.user_id = ? AND d.status = 'active' FOR UPDATE"
    );
    $statement->execute([$draftId, $userId]);
    $draft = $statement->fetch();
    if (!$draft) {
      throw new FitValidationException('DRAFT_NOT_ACTIVE', 'No active draft session was found');
    }
    return $draft;
  }

  private function findDraft(int $userId, int $draftId): ?array {
    $statement = $this->pdo->prepare('SELECT * FROM fit_session_draft WHERE id = ? AND user_id = ?');
    $statement->execute([$draftId, $userId]);
    return $statement->fetch() ?: null;
  }

  private function assertUserExists(int $userId): void {
    $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
    $statement->execute([$userId]);
    if (!$statement->fetchColumn()) {
      throw new FitValidationException('USER_NOT_FOUND', 'User not found');
    }
  }

  private function profileIsConfigured(int $userId): bool {
    $statement = $this->pdo->prepare('SELECT profile_data FROM fit_user_profile WHERE user_id = ?');
    $statement->execute([$userId]);
    $value = $statement->fetchColumn();
    return is_string($value) && trim($value) !== '' && trim($value) !== '{}';
  }

  private function encodeJson(mixed $value): string {
    try {
      return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
      throw new FitValidationException('INVALID_JSON', 'Data cannot be encoded as JSON');
    }
  }

  private function encodeNullableJson(mixed $value): ?string {
    return $value === null ? null : $this->encodeJson($value);
  }

  private function decodeJson(string $value): array {
    try {
      $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
      throw new RuntimeException('Stored Fit JSON is invalid', 0, $exception);
    }
    return is_array($decoded) ? $decoded : [];
  }

  private function decodeNullableJson(?string $value): ?array {
    return $value === null || trim($value) === '' ? null : $this->decodeJson($value);
  }

  private function requiredPositiveInt(mixed $value, string $field): int {
    $result = $this->nullablePositiveInt($value, $field);
    if ($result === null) {
      throw new FitValidationException('FIELD_REQUIRED', "{$field} is required");
    }
    return $result;
  }

  private function nullablePositiveInt(mixed $value, string $field): ?int {
    if ($value === null || $value === '') {
      return null;
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
      throw new FitValidationException('INVALID_NUMBER', "{$field} must be a positive integer or null");
    }
    return (int) $value;
  }

  private function nullableScore(mixed $value, int $min, int $max, string $field): ?int {
    if ($value === null || $value === '') {
      return null;
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
      throw new FitValidationException('INVALID_SCORE', "{$field} must be between {$min} and {$max} or null");
    }
    return (int) $value;
  }

  private function nullableDecimal(mixed $value, float $min, ?float $max, string $field): ?string {
    if ($value === null || $value === '') {
      return null;
    }
    if (!is_numeric($value) || (float) $value < $min || ($max !== null && (float) $value > $max)) {
      throw new FitValidationException('INVALID_NUMBER', "{$field} is outside its allowed range");
    }
    return number_format((float) $value, 3, '.', '');
  }

  private function nullableString(mixed $value, int $maxLength = 65535): ?string {
    if ($value === null) {
      return null;
    }
    if (!is_string($value)) {
      throw new FitValidationException('INVALID_TEXT', 'Text values must be strings or null');
    }
    $value = trim($value);
    if ($value === '') {
      return null;
    }
    if (mb_strlen($value) > $maxLength) {
      throw new FitValidationException('TEXT_TOO_LONG', 'Text value is too long');
    }
    return $value;
  }

  private function nullableDateTime(mixed $value): ?string {
    if ($value === null || $value === '') {
      return null;
    }
    if (!is_string($value)) {
      throw new FitValidationException('INVALID_DATETIME', 'Datetime must be an ISO date-time string or null');
    }
    try {
      return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Exception $exception) {
      throw new FitValidationException('INVALID_DATETIME', 'Datetime is invalid');
    }
  }
}

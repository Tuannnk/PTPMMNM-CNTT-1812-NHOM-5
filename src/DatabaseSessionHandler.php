<?php
declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $query = Database::connection()->prepare(
            'SELECT payload FROM app_sessions WHERE session_id = :id AND last_activity >= :cutoff'
        );
        $query->execute(['id' => $id, 'cutoff' => time() - (int)ini_get('session.gc_maxlifetime')]);
        $payload = $query->fetchColumn();
        return $payload === false ? '' : (string)$payload;
    }

    public function validateId(string $id): bool
    {
        $query = Database::connection()->prepare(
            'SELECT 1 FROM app_sessions WHERE session_id = :id AND last_activity >= :cutoff'
        );
        $query->execute(['id' => $id, 'cutoff' => time() - (int)ini_get('session.gc_maxlifetime')]);
        return $query->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $query = Database::connection()->prepare(
            'UPDATE app_sessions SET payload = :payload, last_activity = :activity WHERE session_id = :id'
        );
        $query->execute(['id' => $id, 'payload' => $data, 'activity' => time()]);
        return $query->rowCount() > 0;
    }

    public function write(string $id, string $data): bool
    {
        $query = Database::connection()->prepare(
            'INSERT INTO app_sessions (session_id, payload, last_activity)
             VALUES (:id, :payload, :activity)
             ON CONFLICT (session_id) DO UPDATE
             SET payload = EXCLUDED.payload, last_activity = EXCLUDED.last_activity'
        );
        return $query->execute(['id' => $id, 'payload' => $data, 'activity' => time()]);
    }

    public function destroy(string $id): bool
    {
        $query = Database::connection()->prepare('DELETE FROM app_sessions WHERE session_id = :id');
        return $query->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $query = Database::connection()->prepare('DELETE FROM app_sessions WHERE last_activity < :cutoff');
        $query->execute(['cutoff' => time() - $max_lifetime]);
        return $query->rowCount();
    }
}

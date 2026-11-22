<?php
/**
 * Centralized NewServ HTTP Client & Online Character Validation Helpers
 *
 * Unifies /y/clients, /y/lobbies, and /y/summary fetches, Shift-JIS/UTF-8
 * sanitization, loading-screen protection, and lobby/game verification across
 * API endpoints, cron workers, and admin pages.
 */

if (!function_exists('newserv_http_get_json')) {
    /**
     * Fetch a JSON array endpoint from $NEWSERV_API_URL with timeout & UTF-8 cleanup.
     */
    function newserv_http_get_json(string $path, float $timeout = 3.0): ?array
    {
        global $NEWSERV_API_URL;
        if (empty($NEWSERV_API_URL)) {
            return null;
        }
        $url = rtrim((string) $NEWSERV_API_URL, '/') . '/' . ltrim($path, '/');
        $ctx = stream_context_create([
            'http' => [
                'timeout'       => $timeout,
                'ignore_errors' => false,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $clean = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $raw) : $raw;
        if (!is_string($clean) || ($clean === '' && $raw !== '')) {
            $clean = $raw;
        }
        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('newserv_fetch_clients')) {
    /**
     * Fetch connected game clients from NewServ /y/clients.
     */
    function newserv_fetch_clients(float $timeout = 3.0): ?array
    {
        return newserv_http_get_json('/y/clients', $timeout);
    }
}

if (!function_exists('newserv_fetch_lobbies')) {
    /**
     * Fetch active lobbies & games from NewServ /y/lobbies.
     */
    function newserv_fetch_lobbies(float $timeout = 3.0): ?array
    {
        return newserv_http_get_json('/y/lobbies', $timeout);
    }
}

if (!function_exists('newserv_fetch_summary')) {
    /**
     * Fetch server summary from NewServ /y/summary.
     */
    function newserv_fetch_summary(float $timeout = 3.0): ?array
    {
        return newserv_http_get_json('/y/summary', $timeout);
    }
}

if (!function_exists('newserv_find_online_client')) {
    /**
     * Find an online client entry for $accountId (optionally matching $characterName).
     */
    function newserv_find_online_client(array $clients, int $accountId, ?string $characterName = null): ?array
    {
        foreach ($clients as $c) {
            if (!isset($c['Account']['AccountID']) || (int) $c['Account']['AccountID'] !== $accountId) {
                continue;
            }
            if ($characterName !== null && $characterName !== '') {
                if (!isset($c['Name']) || $c['Name'] !== $characterName) {
                    continue;
                }
            }
            return $c;
        }
        return null;
    }
}

if (!function_exists('newserv_is_client_loading')) {
    /**
     * Detect transient loading-screen telemetry where NewServ zeroes EXP on level > 1 chars.
     */
    function newserv_is_client_loading(array $client): bool
    {
        return !isset($client['EXP']) || ($client['EXP'] === 0 && ((int) ($client['Level'] ?? 1) > 1));
    }
}

if (!function_exists('newserv_resolve_target_ident')) {
    /**
     * Resolve an unambiguous alphanumeric BB License username to bypass NewServ hex/dec collisions.
     */
    function newserv_resolve_target_ident(array $client, $fallbackIdent): string
    {
        if (!empty($client['Account']['BBLicenses'][0]['UserName'])) {
            return (string) $client['Account']['BBLicenses'][0]['UserName'];
        }
        return (string) $fallbackIdent;
    }
}

if (!function_exists('newserv_is_client_in_game')) {
    /**
     * Determine whether an online client is actively inside a game room (IsGame == true).
     */
    function newserv_is_client_in_game(array $client, array $lobbies, ?int $accountId = null): bool
    {
        $lobbyId = $client['LobbyID'] ?? null;
        $targetAccId = $accountId ?? (isset($client['Account']['AccountID']) ? (int) $client['Account']['AccountID'] : null);

        foreach ($lobbies as $l) {
            if (empty($l['IsGame'])) {
                continue;
            }
            if ($lobbyId !== null && isset($l['ID']) && $l['ID'] == $lobbyId) {
                return true;
            }
            if ($targetAccId !== null && isset($l['Clients']) && is_array($l['Clients'])) {
                foreach ($l['Clients'] as $lc) {
                    if (is_array($lc) && isset($lc['Account']['AccountID']) && (int) $lc['Account']['AccountID'] === $targetAccId) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}

if (!function_exists('newserv_emit_json_error')) {
    /**
     * Emit a JSON error payload with HTTP status code and terminate request execution.
     */
    function newserv_emit_json_error(int $httpCode, string $message, array $extra = []): void
    {
        http_response_code($httpCode);
        echo json_encode(array_merge(['error' => $message], $extra));
        exit;
    }
}

if (!function_exists('newserv_require_online_character')) {
    /**
     * Validate that $accountId (and optional $characterName) is online, spawned, and
     * satisfies lobby/game state requirements; emits JSON error & exits on failure.
     */
    function newserv_require_online_character(int $accountId, ?string $characterName = null, array $opts = []): array
    {
        $timeout         = (float) ($opts['timeout'] ?? 3.0);
        $includeSuccess  = !empty($opts['include_success_false']);
        $includeOffline  = !empty($opts['include_offline_flag']);
        $offlineCode     = (int) ($opts['offline_http_code'] ?? 500);
        $offlineErr      = (string) ($opts['offline_error'] ?? 'Game server is offline, cannot verify character.');
        $notOnlineCode   = (int) ($opts['not_online_http_code'] ?? 400);
        $notOnlineErr    = $opts['not_online_error'] ?? null;
        $checkLoading    = array_key_exists('check_loading', $opts) ? (bool) $opts['check_loading'] : true;
        $loadingErr      = (string) ($opts['loading_error'] ?? 'Your character is currently in a loading screen. Please wait until you are fully spawned to claim.');
        $requireInGame   = !empty($opts['require_in_game']);
        $inGameReqErr    = (string) ($opts['in_game_required_error'] ?? 'You must be actively inside a game (not in a lobby) to claim a reward.');
        $forbidInGame    = !empty($opts['forbid_in_game']);
        $inGameForbCode  = (int) ($opts['in_game_forbidden_http_code'] ?? 403);
        $inGameForbErr   = (string) ($opts['in_game_forbidden_error'] ?? 'You cannot perform this action while actively inside a game. Please return to the lobby first!');

        $baseExtra = $includeSuccess ? ['success' => false] : [];

        $clients = newserv_fetch_clients($timeout);
        if ($clients === null) {
            $extra = $baseExtra;
            if ($includeOffline) {
                $extra['offline'] = true;
            }
            newserv_emit_json_error($offlineCode, $offlineErr, $extra);
        }

        $client = newserv_find_online_client($clients, $accountId, $characterName);
        if ($client === null) {
            $err = $notOnlineErr !== null
                ? (string) $notOnlineErr
                : (($characterName !== null && $characterName !== '')
                    ? "You must be logged in as {$characterName} to claim this reward!"
                    : 'Character must be online to claim rewards.');
            $extra = $baseExtra;
            if ($includeOffline) {
                $extra['offline'] = true;
            }
            newserv_emit_json_error($notOnlineCode, $err, $extra);
        }

        if ($checkLoading && newserv_is_client_loading($client)) {
            newserv_emit_json_error(400, $loadingErr, $baseExtra);
        }

        if ($requireInGame || $forbidInGame) {
            if ($requireInGame && ($client['LobbyID'] ?? null) === null) {
                newserv_emit_json_error(400, 'Character must be actively logged into a server lobby or game.', $baseExtra);
            }

            $lobbies = newserv_fetch_lobbies($timeout);
            if ($lobbies === null) {
                $lobbiesFailCode = $offlineCode >= 500 ? $offlineCode : 500;
                $extra = $baseExtra;
                if ($includeOffline) {
                    $extra['offline'] = true;
                }
                newserv_emit_json_error($lobbiesFailCode, 'Game server lobbies offline, cannot verify game status.', $extra);
            }

            $inGame = newserv_is_client_in_game($client, $lobbies, $accountId);
            if ($requireInGame && !$inGame) {
                newserv_emit_json_error(400, $inGameReqErr, $baseExtra);
            }
            if ($forbidInGame && $inGame) {
                newserv_emit_json_error($inGameForbCode, $inGameForbErr, $baseExtra);
            }
        }

        return $client;
    }
}

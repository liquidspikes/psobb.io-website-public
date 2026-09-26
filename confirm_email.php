<?php
/**
 * PSOBB: Confirm Account Recovery Email
 * 
 * Validates the confirmation token sent to the user's email address.
 * Activates and links the email to the user's game account.
 */
$page_title = 'Confirm Recovery Email - PSOBB Private Server';
include 'includes/header.php';

require_once 'api/config.php';
require_once 'api/db.php';

$token = preg_replace('/[^a-f0-9]/i', '', trim($_GET['token'] ?? ''));

$status = 'error'; // 'success', 'already_confirmed', 'expired', 'invalid', 'no_token', 'conflict'
$confirmedEmail = '';
$targetUsername = '';
$errorMessage = '';

if (empty($token)) {
    $status = 'no_token';
} else {
    try {
        $db = get_db();
        $db->exec("CREATE TABLE IF NOT EXISTS email_confirmations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            new_email TEXT NOT NULL,
            token TEXT UNIQUE NOT NULL,
            confirmed_at INTEGER DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at INTEGER NOT NULL
        )");

        $stmt = $db->prepare("SELECT * FROM email_confirmations WHERE token = :t");
        $stmt->bindValue(':t', $token, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

        if (!$row) {
            $status = 'invalid';
        } elseif (!empty($row['confirmed_at'])) {
            $status = 'already_confirmed';
            $confirmedEmail = $row['new_email'];
            $targetUsername = $row['username'];
        } elseif ((int)$row['expires_at'] < time()) {
            $status = 'expired';
        } else {
            $accountId = (int)$row['account_id'];
            $username = $row['username'];
            $newEmail = strtolower(trim($row['new_email']));

            // Ensure email isn't claimed by another account in the meantime
            $chk = $db->prepare("SELECT id FROM users WHERE email = :e COLLATE NOCASE AND account_id != :aid");
            $chk->bindValue(':e', $newEmail, SQLITE3_TEXT);
            $chk->bindValue(':aid', $accountId, SQLITE3_INTEGER);
            $existing = $chk->execute()->fetchArray(SQLITE3_ASSOC);

            if ($existing) {
                $status = 'conflict';
            } else {
                // 1. Mark token as confirmed
                $updTok = $db->prepare("UPDATE email_confirmations SET confirmed_at = :now WHERE token = :t");
                $updTok->bindValue(':now', time(), SQLITE3_INTEGER);
                $updTok->bindValue(':t', $token, SQLITE3_TEXT);
                $updTok->execute();

                // 2. Update users table with verified email
                $uStmt = $db->prepare("SELECT id, language FROM users WHERE account_id = :aid OR username = :u");
                $uStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
                $uStmt->bindValue(':u', $username, SQLITE3_TEXT);
                $userRow = $uStmt->execute()->fetchArray(SQLITE3_ASSOC);

                if ($userRow) {
                    $updUser = $db->prepare("UPDATE users SET email = :e WHERE id = :id");
                    $updUser->bindValue(':e', $newEmail, SQLITE3_TEXT);
                    $updUser->bindValue(':id', (int)$userRow['id'], SQLITE3_INTEGER);
                    $updUser->execute();
                } else {
                    $insUser = $db->prepare("INSERT INTO users (username, email, account_id, receive_system_mail, receive_discord_streak_msg) VALUES (:u, :e, :aid, 1, 1)");
                    $insUser->bindValue(':u', $username, SQLITE3_TEXT);
                    $insUser->bindValue(':e', $newEmail, SQLITE3_TEXT);
                    $insUser->bindValue(':aid', $accountId, SQLITE3_INTEGER);
                    $insUser->execute();
                }

                // 3. Update session if user is logged into this account
                if (!empty($_SESSION['user']) && (int)($_SESSION['user']['account_id'] ?? 0) === $accountId) {
                    $_SESSION['user']['email'] = $newEmail;
                    $_SESSION['user']['has_email'] = true;
                    $_SESSION['user']['is_legacy_email'] = false;
                    $_SESSION['user']['is_pending_email'] = false;
                    unset($_SESSION['user']['pending_email']);
                }

                // 4. Send Confirmation Completed Notification
                $lang_pref = $userRow['language'] ?? ($_COOKIE['psobb_lang'] ?? 'en');
                if ($lang_pref === 'jp') {
                    $subject = "リカバリー用メールアドレス設定完了 - PSOBB.IO";
                    $msg = "$username さん、\n\nPSOBB.IOアカウント ($username) のリカバリー用メールアドレス ($newEmail) の確認が完了し、正常に連携されました。\n\n今後はパスワードを忘れた場合でも、以下のパスワード再設定ページから再設定が可能です：\nhttps://psobb.io/forgot_password.php\n\n心当たりがない場合は、直ちに管理者にご連絡ください。\n\n良い狩りを！\nPSOBB.IO チーム";
                } else {
                    $subject = "Recovery Email Confirmed - PSOBB.IO";
                    $msg = "Hello $username,\n\nYour recovery email address ($newEmail) has been successfully verified and linked to your PSOBB.IO account ($username).\n\nYou can now use this email address on the Forgot Password page to reset your password if you ever lose or forget it:\nhttps://psobb.io/forgot_password.php\n\nIf you did not make this change, please contact an administrator immediately.\n\nHappy Hunting,\nPSOBB.IO Team";
                }
                @send_email($newEmail, $subject, $msg);

                $status = 'success';
                $confirmedEmail = $newEmail;
                $targetUsername = $username;
            }
        }
    } catch (Exception $e) {
        $status = 'error';
        $errorMessage = $e->getMessage();
    }
}
?>

<main class="container">
    <div class="login-container" style="max-width: 520px; margin: 0 auto; margin-top: 4rem; margin-bottom: 4rem;">
        <div class="login-container-form" style="background: rgba(10, 15, 25, 0.95); border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 8px; padding: 2.5rem; box-shadow: 0 0 30px rgba(0, 255, 255, 0.15);">
            <?php if ($status === 'success'): ?>
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-check-circle" style="color: #00C851; font-size: 3.5rem; text-shadow: 0 0 20px rgba(0, 200, 81, 0.5);"></i>
                </div>
                <h2 style="color: #00C851; text-align: center; margin-top: 0; margin-bottom: 0.75rem; font-family: 'Share Tech Mono', monospace; font-size: 1.5rem;">
                    <?= __('Recovery Email Confirmed!') ?>
                </h2>
                <p style="text-align: center; color: #ccc; line-height: 1.6; margin-bottom: 1.5rem; font-size: 0.95rem;">
                    <?= __('Your recovery email address has been successfully verified and linked to your account.') ?>
                </p>
                <div style="background: rgba(0, 200, 81, 0.08); border: 1px solid rgba(0, 200, 81, 0.25); border-radius: 6px; padding: 14px 18px; margin-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 6px;">
                        <span style="color: #888; font-size: 0.85rem; font-family: 'Share Tech Mono', monospace;"><?= __('Account') ?></span>
                        <strong style="color: #fff; font-family: 'Share Tech Mono', monospace;"><?= htmlspecialchars($targetUsername) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #888; font-size: 0.85rem; font-family: 'Share Tech Mono', monospace;"><?= __('Recovery Email') ?></span>
                        <strong style="color: #00ffff; font-family: 'Share Tech Mono', monospace;"><?= htmlspecialchars($confirmedEmail) ?></strong>
                    </div>
                </div>
                <p style="font-size: 0.85rem; color: #888; margin-bottom: 2rem; text-align: center; line-height: 1.5;">
                    <?= __('You can now recover your password on the Forgot Password page if you ever lose or forget it.') ?>
                </p>
                <div style="text-align: center;">
                    <a href="login.php" class="dl-btn" style="display: inline-block; padding: 10px 28px; text-decoration: none; font-size: 0.95rem;">
                        <i class="fas fa-tachometer-alt" style="margin-right: 8px;"></i><?= !empty($_SESSION['user']) ? __('Go to Dashboard') : __('Go to Login') ?>
                    </a>
                </div>

            <?php elseif ($status === 'already_confirmed'): ?>
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-check-double" style="color: #00ffff; font-size: 3.5rem; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5);"></i>
                </div>
                <h2 style="color: #00ffff; text-align: center; margin-top: 0; margin-bottom: 0.75rem; font-family: 'Share Tech Mono', monospace; font-size: 1.5rem;">
                    <?= __('Email Already Confirmed') ?>
                </h2>
                <p style="text-align: center; color: #ccc; line-height: 1.6; margin-bottom: 1.5rem; font-size: 0.95rem;">
                    <?= __('This recovery email address has already been confirmed and is active on your account.') ?>
                </p>
                <div style="background: rgba(0, 255, 255, 0.08); border: 1px solid rgba(0, 255, 255, 0.25); border-radius: 6px; padding: 14px 18px; margin-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #888; font-size: 0.85rem; font-family: 'Share Tech Mono', monospace;"><?= __('Recovery Email') ?></span>
                        <strong style="color: #00ffff; font-family: 'Share Tech Mono', monospace;"><?= htmlspecialchars($confirmedEmail) ?></strong>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 1.5rem;">
                    <a href="login.php" class="dl-btn" style="display: inline-block; padding: 10px 28px; text-decoration: none; font-size: 0.95rem;">
                        <i class="fas fa-tachometer-alt" style="margin-right: 8px;"></i><?= !empty($_SESSION['user']) ? __('Go to Dashboard') : __('Go to Login') ?>
                    </a>
                </div>

            <?php elseif ($status === 'expired'): ?>
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-hourglass-end" style="color: #ffaa00; font-size: 3.5rem; text-shadow: 0 0 20px rgba(255, 170, 0, 0.5);"></i>
                </div>
                <h2 style="color: #ffaa00; text-align: center; margin-top: 0; margin-bottom: 0.75rem; font-family: 'Share Tech Mono', monospace; font-size: 1.5rem;">
                    <?= __('Confirmation Link Expired') ?>
                </h2>
                <p style="text-align: center; color: #ccc; line-height: 1.6; margin-bottom: 2rem; font-size: 0.95rem;">
                    <?= __('This confirmation link has expired. Confirmation links are valid for 24 hours. Please log in and request a new confirmation link.') ?>
                </p>
                <div style="text-align: center;">
                    <a href="login.php" class="dl-btn" style="display: inline-block; padding: 10px 28px; text-decoration: none; font-size: 0.95rem; border-color: #ffaa00; color: #ffaa00;">
                        <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i><?= __('Back to Login') ?>
                    </a>
                </div>

            <?php else: ?>
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-exclamation-triangle" style="color: #ff4444; font-size: 3.5rem; text-shadow: 0 0 20px rgba(255, 68, 68, 0.5);"></i>
                </div>
                <h2 style="color: #ff4444; text-align: center; margin-top: 0; margin-bottom: 0.75rem; font-family: 'Share Tech Mono', monospace; font-size: 1.5rem;">
                    <?= __('Invalid Confirmation Link') ?>
                </h2>
                <p style="text-align: center; color: #ccc; line-height: 1.6; margin-bottom: 2rem; font-size: 0.95rem;">
                    <?php if ($status === 'conflict'): ?>
                        <?= __('This email address has already been claimed by another account.') ?>
                    <?php elseif ($status === 'no_token'): ?>
                        <?= __('Invalid request. No confirmation token was provided.') ?>
                    <?php else: ?>
                        <?= __('This confirmation link is invalid or has already been used. Please log in to check your account settings.') ?>
                    <?php endif; ?>
                </p>
                <div style="text-align: center;">
                    <a href="login.php" class="dl-btn" style="display: inline-block; padding: 10px 28px; text-decoration: none; font-size: 0.95rem; border-color: #ff4444; color: #ff4444;">
                        <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i><?= __('Back to Login') ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>

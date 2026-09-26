<?php
$page_title = "Register";
$current_page = "register";
include 'includes/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div class="auth-card">
            <h2><?= __('Create Account') ?></h2>
            <?php if (!is_feature_enabled('registration')): ?>
                <div style="background: rgba(255, 68, 68, 0.15); border: 1px solid #ff4444; border-radius: 8px; padding: 1.5rem; text-align: center; margin: 1.5rem 0;">
                    <i class="fas fa-lock fa-3x" style="color: #ff4444; margin-bottom: 1rem;"></i>
                    <h3 style="margin-top: 0; color: #ff6666;"><?= __('Registration Closed') ?></h3>
                    <p style="margin: 0; color: #eee; font-size: 0.95rem; line-height: 1.5;">
                        <?= __('Public registration is currently disabled by the server administrator.') ?>
                    </p>
                </div>
                <div class="auth-links">
                    <p><?= __('Already have an account?') ?> <a href="login"><?= __('Login here') ?></a></p>
                </div>
            <?php else: ?>
                <p><?= __('Register for Phantasy Star Online Blue Burst.') ?></p>
                
                <form id="register-form">
                    <div class="form-group">
                        <label for="username"><?= __('Username') ?></label>
                        <input type="text" id="username" name="username" required maxlength="16" pattern="[a-zA-Z0-9_-]+" title="<?= __('Letters, numbers, dashes/underscores only. Max 16 chars.') ?>">
                    </div>

                    <div class="form-group">
                        <label for="email"><?= __('Email Address') ?></label>
                        <input type="email" id="email" name="email" required placeholder="name@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="password"><?= __('Password') ?></label>
                        <input type="password" id="password" name="password" required maxlength="16">
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm"><?= __('Confirm Password') ?></label>
                        <input type="password" id="password_confirm" name="password_confirm" required maxlength="16">
                    </div>
                    
                    <div id="register-error" class="error-message" style="display:none; color: #ff4444; margin-bottom: 1rem; text-align: center;"></div>
                    <div id="register-success" class="success-message" style="display:none; color: #00C851; margin-bottom: 1rem; text-align: center;"></div>

                    <button type="submit" class="btn btn-primary btn-block"><?= __('Sign Up') ?></button>
                </form>
                
                <div class="auth-links">
                    <p><?= __('Already have an account?') ?> <a href="login"><?= __('Login here') ?></a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
document.getElementById('register-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const user = document.getElementById('username').value.toLowerCase();
    const email = document.getElementById('email').value;
    const pass = document.getElementById('password').value;
    const confirm = document.getElementById('password_confirm').value;
    const errBox = document.getElementById('register-error');
    const succBox = document.getElementById('register-success');
    const btn = this.querySelector('button');
    
    errBox.style.display = 'none';
    succBox.style.display = 'none';
    
    if (pass !== confirm) {
        errBox.textContent = "<?= __('Passwords do not match.') ?>";
        errBox.style.display = 'block';
        return;
    }
    
    if (user.includes(' ') || pass.includes(' ')) {
        errBox.textContent = "<?= __('Username and password cannot contain spaces.') ?>";
        errBox.style.display = 'block';
        return;
    }
    
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = "<?= __('Creating Account...') ?>";
    
    try {
        const response = await fetch('api/register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: user, email: email, password: pass })
        });
        
        const data = await response.json();
        
        if (response.ok && data.success) {
            succBox.textContent = (data.message || '') + " <?= __('Redirecting to login...') ?>";
            succBox.style.display = 'block';
            this.reset();
            setTimeout(() => window.location.href = 'login', 2000);
        } else {
            errBox.textContent = data.error || "<?= __('Registration failed.') ?>";
            errBox.style.display = 'block';
            btn.disabled = false;
            btn.textContent = originalText;
        }
    } catch (e) {
        errBox.textContent = "<?= __('Connection error. Please try again.') ?>";
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = originalText;
    }
});
</script>

<?php include 'includes/footer.php'; ?>

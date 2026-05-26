<?php
$page_title = 'FAQ - PSOBB Private Server';
$current_page = 'faq';
include 'includes/header.php';
?>

<link rel="stylesheet" href="css/faq.css?v=<?php echo time(); ?>">

<main class="container faq-page">
    <div class="main-header">
        <h1><?= __('Frequently Asked Questions') ?></h1>
        <p><?= __('Answers about our beta server, gameplay rules, and development. For reward claiming details, see the <a href="rewards.php">Rewards Guide</a>.') ?></p>
    </div>

    <div class="faq-category">
        <h2><?= __('About PSOBB.io') ?></h2>

        <details class="faq-item" open>
            <summary><?= __('What makes PSOBB.io unique?') ?></summary>
            <div class="faq-answer">
                <p><?= __('We are in beta; there is hardly anything that is not being worked on and improved. Our Discord bot, Hex, has the keys to server events and the website integration. The <a href="rewards.php">website-based rewards system</a> is completely original to us. Play with your web browser open on your phone and initiate in-game drops and commands for a one-of-a-kind play experience. Join us on <a href="https://discord.gg/28s84HJXha" target="_blank" rel="noopener">Discord</a>.') ?></p>
            </div>
        </details>
    </div>

    <div class="faq-category">
        <h2><?= __('Gameplay & balance') ?></h2>

        <details class="faq-item">
            <summary><?= __('"But the ECONOMY!"') ?></summary>
            <div class="faq-answer">
                <p><?= __('This community is currently dominated by those who are interested in testing gameplay dynamics, mods, and new experiences. While we understand the desire to keep things satisfying and fair, the nature of being early in the testing phase is such that we are still trying to find the right balance. We won\'t always agree, but you can state your opinion and be heard regardless.') ?></p>
            </div>
        </details>

        <details class="faq-item">
            <summary><?= __('Are there any changes to drop rates?') ?></summary>
            <div class="faq-answer">
                <p><?= __('No. newserv defaults are standard. The only changes to drop rates would be if Hex initiates a community vote for a drop rate bonus and it wins.') ?></p>
            </div>
        </details>

        <details class="faq-item">
            <summary><?= __('Are there any changes to classes?') ?></summary>
            <div class="faq-answer">
                <p><?= __('No — vanilla classes.') ?></p>
            </div>
        </details>

        <details class="faq-item">
            <summary><?= __('Can I import my stuff from another server?') ?></summary>
            <div class="faq-answer">
                <p><?= __('No, and there are no current plans to implement this. New server, new you.') ?></p>
            </div>
        </details>

        <details class="faq-item">
            <summary><?= __('Can you add this feature from another server?') ?></summary>
            <div class="faq-answer">
                <p><?= __('Maybe. Check out the <a href="https://feedback.psobb.io" target="_blank" rel="noopener">feature request page</a>, submit your suggestion, and upvote features that are important to you.') ?></p>
            </div>
        </details>
    </div>

    <div class="faq-category">
        <h2><?= __('Bounties & website') ?></h2>

        <details class="faq-item">
            <summary><?= __('My bounty is broken!') ?></summary>
            <div class="faq-answer">
                <p><?= __('It is super helpful to send a screenshot of your <a href="missions.php">bounty</a> to the <a href="https://discord.gg/28s84HJXha" target="_blank" rel="noopener">Discord support channel</a> and add the details of what you did to try and finish or claim the bounty. We will try to fix it as soon as we can. We can often correct the bounty and allow you to claim it if something broke.') ?></p>
            </div>
        </details>
    </div>

    <div class="faq-category">
        <h2><?= __('Development & support') ?></h2>

        <details class="faq-item">
            <summary><?= __('"The decomp is stuck!"') ?></summary>
            <div class="faq-answer">
                <p><?= __('I assure you, if the decomp is stuck, LiquidSpikes knows. If he is not sternly talking The Swarm back into submission, he is probably working at his day job, his side business, or spending time with his kids — ranging in age from the oldest at 5 years to the youngest at 9 months. Your patience and respect for his personhood is appreciated. Half the reason that <a href="stats.php">status page</a> exists is so he can monitor it anywhere, anytime.') ?></p>
            </div>
        </details>
    </div>
</main>

<?php include 'includes/footer.php'; ?>

# mt-github-webhook
GitHub Webhooks made easier

An easy-to-use php library for reacting to [GitHub Webhooks](https://developer.github.com/webhooks/ "Official Webhooks documentation").

Currently supported event names:
* `PUSH`

Currently provided actions:
* Update your server filesystem with the changes pushed to a specific branch
* Filter changes by repo tree path
* Output a list of changes, viewable from the GitHub Webhook settings page

## Example of use:
    <?php
    require_once 'mt-github-webhook.php';
    
    \MT\GitHub\Webhook::onPushToBranch('qa-testing')->
                        forChangesInFolder('main-web-site/public_html')->
                        setGitHubCredentials('github-username', 'My5ecretP@ssw0rd')->
                        pushChangesToFolder('/www/sites/qa.domain.com/public_html');
    
    \MT\GitHub\Webhook::onPushToBranch('production')->
                        forChangesInFolder('main-web-site/public_html')->
                        setGitHubCredentials('github-username', 'My5ecretP@ssw0rd')->
                        pushChangesToFolder('/www/sites/www.domain.com/public_html');
    ?>

Future plans:
* Lots of them...

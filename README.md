# mt-github-webhook
GitHub Webhooks made easier

An easy-to-use php library for reacting to [GitHub Webhooks](https://developer.github.com/webhooks/ "Official Webhooks documentation").

## Currently supported event names:
* `PUSH`

## Currently provided actions:
* Update your server filesystem with the changes pushed to a specific branch
* Filter changes by repository tree path
* Output a list of changes, viewable from the GitHub Webhook settings page

## Example of use:
```php
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
```

### How-to
1. Put something like the code above in a .php file and put it on your web server. Be sure to provide the correct branch names, folder names, GitHub user account name and password.
2. Go to your GitHub repository's settings page
3. Click the **Add webhook** button
4. Provide the public url for your .php file in the *Payload URL* field
5. Click the **Add webhook** button
6. Done!

The repository settings page has a **Recent deliveries** section. If you need to debug the Webhook handler, you can always inspect the response from the handler, and even make GitHub **Redeliver** the payload of any previous event.

## Future plans:
* Making use of the **SECRET** provided by GitHub's webhook mechanism
* Examining the use of `OAUTH-TOKEN` instead of HTTP authentication
* Reacting intelligently to other events than just `PUSH`
* Providing more different actions to take than just listing changes or pushing them into a directory

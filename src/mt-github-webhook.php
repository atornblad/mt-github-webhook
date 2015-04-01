<?php
/**
 * GitHub Webhooks made easier
 * 
 * Copyright (C) 2015 Anders Tornblad
 * 
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 * 
 * @author   Anders Tornblad
 * @package  mt-github
 * @license  http://www.gnu.org/licenses/gpl-2.0.txt
 * @link     http://atornblad.se/labels/mt-github-webhook
 */

namespace MT\GitHub;

/**
 * Contains methods for reacting to GitHub Webhook events
 * 
 * @link    http://atornblad.se/labels/mt-github-webhook
 */
class Webhook {
	private static $rawInput;
	private static $input;
	private static $eventName;
	
	private static function ensureInitDone() {
		if (!isset(Webhook::$input)) {
			Webhook::$rawInput = file_get_contents('php://input');
			Webhook::$input = json_decode(Webhook::$rawInput);
			Webhook::$eventName = strtolower(@$_SERVER['HTTP_X_GITHUB_EVENT']);
		}
	}
	
	/**
	 * React to PUSH events on a specific branch.
	 * 
	 * @param string $branchName Name of branch to watch.
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public static function onPushToBranch($branchName) {
		Webhook::ensureInitDone();
		
		if (Webhook::$eventName == 'push' && Webhook::$input->ref == "refs/heads/$branchName") {
			return new WebhookPushHandler($branchName, Webhook::$input->repository->full_name, Webhook::$input->commits);
		} else {
			return WebhookPushHandler::createDummy();
		}
	}
	
	/**
	 * Check the X-Hub-Signature request header
	 *
	 * @param string $secret The secret phrase entered in the GitHub Webhook management page.
	 * @return bool
	 */
	public static function isRequestSecure($secret) {
		Webhook::ensureInitDone();
		
		$serverSig = @$_SERVER['HTTP_X_HUB_SIGNATURE'];;
		if ($serverSig) {
			$expected = 'sha1=' . hash_hmac('sha1', Webhook::$rawInput, $secret, false );
			
			return ($expected == $serverSig);
		} else {
			return false;
		}
	}
}

/**
 * Handles GitHub PUSH events
 */
class WebhookPushHandler {
	private $branchName;
	private $repositoryFullName;
	private $isActive;
	private $folderName;
	private $changes;
	private $curlUserPwd;
	private $addThisComment;
	
	public function __construct($branchName, $repositoryFullName, $commits = null) {
		$this->branchName = $branchName;
		$this->repositoryFullName = $repositoryFullName;
		$this->isActive = true;
		$this->folderName = '';
		$this->curlUserPwd = '';
		$this->addThisComment = '';
		
		if (isset($commits)) {
			$this->parseCommits($commits);
		}
	}
	
	private function makeInactiveCopy() {
		$result = new WebhookPushHandler($this->branchName, $this->repositoryFullName, null);
		$result->isActive = false;
		$result->changes = $this->changes;
		$result->curlUserPwd = $this->curlUserPwd;
		return $result;
	}
	
	private function parseCommits($commits) {
		$this->changes = [];
		
		foreach ($commits as $commit) {
			$this->parseCommit($commit);
		}
	}
	
	private function parseCommit($commit) {
		foreach ($commit->added as $addedPath) {
			$this->changes[$addedPath] = 'added';
		}
		foreach ($commit->modified as $modifiedPath) {
			$this->changes[$modifiedPath] = 'modified';
		}
		foreach ($commit->removed as $removedPath) {
			$this->changes[$removedPath] = 'removed';
		}
	}
	
	/**
	 * Terminates the request with a 403 Forbidden if request is not secure
	 * 
	 * @param string $secret The secret phrase entered in the GitHub Webhook management page.
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function validateSecretOrDie($secret) {
		if (Webhook::isRequestSecure($secret)) {
			return $this;
		} else {
			http_response_code(403);
			exit('Correct signature was not provided. Check the SECRET in your repository Webhook settings.');
		}
	}
	
	/**
	 * Sets username and password for communication with GitHub servers.
	 * 
	 * @param string $username GitHub username.
	 * @param string $password GitHub password.
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function setGitHubCredentials($username, $password) {
		$this->curlUserPwd = "$username:$password";
		return $this;
	}
	
	public static function createDummy() {
		$result = new WebhookPushHandler(null, '');
		$result->isActive = false;
		return $result;
	}
	
	/**
	 * Filters changes by directory name.
	 * 
	 * @param string $folderName Name of folder relative to the repo/branch root.
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function forChangesInFolder($folderName) {
		if ($this->folderName) {
			$newHandler =$this->makeInactiveCopy();
			return $newHandler->forChangesInFolder($folderName);
		}
		
		$this->folderName = $folderName;
		return $this;
	}
	
	private function handleChanges(callable $callback) {
		if (!$this->isActive) return;
		
		if ($this->folderName) {
			$pathStart = $this->folderName . '/';
		} else {
			$pathStart = '';
		}
		
		$pathStartLen = strlen($pathStart);
		
		foreach ($this->changes as $path => $changeType) {
			if ($this->folderName) {
				if (substr($path, 0, $pathStartLen) == $pathStart) {
					$localName = substr($path, $pathStartLen);
					call_user_func_array($callback, [$localName, $changeType]);
				}
			} else {
				call_user_func_array($callback, [$path, $changeType]);
			}
		}
		
		return $this;
	}
	
	/**
	 * Invokes a callback function, passing all changes as an associative array.
	 * 
	 * The callback function must take one parameter. When invoked, the argument value
	 * is an associative array where the keys are paths, and each value is the type
	 * of change, from [ 'added', 'modified', 'removed' ]
	 * 
	 * @param callable $callback Function that gets an associative array as an argument
	 */
	public function invokeWithArrayOfChanges(callable $callback) {
		call_user_func_array($callback, [ $this->changes ]);
		return $this;
	}
	
	/**
	 * Invokes a callback function once for each change
	 * 
	 * The callback function must take two parameters. The first is the path of the
	 * changed file, the second is the type of change, from [ 'added', 'modified', 'removed' ]
	 * 
	 * @param callable $callback Function that gets path and change-type as arguments
	 */
	public function invokeForEachChange(callable $callback) {
		return $this->handleChanges($callback);
	}
	
	/**
	 * Outputs a simple list of changes.
	 * 
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function listChanges() {
		return $this->handleChanges(function($a, $b) { $this->echoChange($a, $b); });
	}
	
	private function echoChange($path, $changeType) {
		echo "$path: $changeType\r\n";
	}
	
	/**
	 * Pushes the changes to a local server directory.
	 * 
	 * @param string $folder Name of local server directory to receive changes.
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function pushChangesToFolder($folder) {
		return $this->handleChanges(function($a, $b) use ($folder) { $this->pushChange($a, $b, $folder); });
	}
	
	/**
	 * Sets a comment to be included automatically, whenever possible.
	 *
	 * Looks at the file name extension, and in some cases the
	 * file contents, to determine how to add the comment
	 * @param string $comment
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function setComment($comment) {
		$this->addThisComment = $comment;
		
		return $this;
	}
	
	private function pushChange($path, $changeType, $folder) {
		$targetPath = "$folder/$path";
		
		if ($changeType == 'removed') {
			echo "Deleting $targetPath\r\n";
			unlink($targetPath);
		} else {
			$sourceUrl = "https://raw.githubusercontent.com/{$this->repositoryFullName}/{$this->branchName}/";
			if ($this->folderName) {
				$sourceUrl .= "{$this->folderName}/";
			}
			$sourceUrl .= $path;
			
			echo "Downloading $sourceUrl to $targetPath\r\n";
			
			$dirname = dirname($targetPath);
			if (!file_exists($dirname)) {
				echo "    But first: creating directory $dirname!\r\n";
				mkdir($dirname, 0777, true);
			}
			
			$output = fopen($targetPath, 'wb');
			
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_FILE, $output);
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_URL, $sourceUrl);
			if ($this->curlUserPwd) {
				curl_setopt($curl, CURLOPT_USERPWD, $this->curlUserPwd);
			}
			curl_exec($curl);
			curl_close($curl);
			fclose($output);
			
			if ($this->addThisComment) {
				$this->applyComment($targetPath, $this->addThisComment);
			}
		}
	}
	
	private function applyComment($path, $comment) {
		$lowerPath = mb_strtolower($path);
		
		if (substr($lowerPath, -4) == '.php') {
			$temp = file_get_contents($path);
			if (substr($temp, 0, 5) == '<?php') {
				$temp = "<?php\r\n/* $comment */" . substr($temp, 5);
				file_put_contents($path, $temp);
				echo "Added PHP comment\r\n";
			}
		}
		
		if (substr($lowerPath, -4) == '.css') {
			$temp = file_get_contents($path);
			$temp = "/* $comment */\r\n" . $temp;
			file_put_contents($path, $temp);
			echo "Added CSS comment\r\n";
		}
		
		if (substr($lowerPath, -3) == '.js') {
			$temp = file_get_contents($path);
			$temp = "/* $comment */\r\n" . $temp;
			file_put_contents($path, $temp);
			echo "Added JS comment\r\n";
		}
	}
}

?>
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
 * This is a static class, currently containing just one useful method.
 * 
 * @link    http://atornblad.se/labels/mt-github-webhook
 */
class Webhook {
	private static $input;
	private static $eventName;
	
	private static function ensureInitDone() {
		if (!isset(Webhook::$input)) {
			$jsonencodedInput = file_get_contents('php://input');
			Webhook::$input = json_decode($jsonencodedInput);
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
	
	public function __construct($branchName, $repositoryFullName, $commits = null) {
		$this->branchName = $branchName;
		$this->repositoryFullName = $repositoryFullName;
		$this->isActive = true;
		$this->folderName = '';
		$this->curlUserPwd = '';
		
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
	 * Sets GitHub credentials for downloading raw contents using HTTP Authentication.
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
		$result = new WebhookPushHandler(null);
		$result->isActive = false;
		return $result;
	}
	
	/**
	 * Looks for changes in a specific folder only.
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
					$callback($localName, $changeType);
				}
			} else {
				$callback($path, $changeType);
			}
		}
		
		return $this;
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
	 * Pushes the changes relative to the directory being watched, to a local server directory.
	 * 
	 * @param string $folder Name of local server directory to receive changes.
	 * @return \MT\GitHub\WebhookPushHandler
	 */
	public function pushChangesToFolder($folder) {
		return $this->handleChanges(function($a, $b) use ($folder) { $this->pushChange($a, $b, $folder); });
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
		}
	}
}

?>
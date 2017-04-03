<?php
namespace App\Test\TestCase\Shell\TrimBranchesTest;

use ArtSkills\Lib\Git;
use ArtSkills\Lib\GitBranchTrim;
use ArtSkills\TestSuite\AppTestCase;
use ArtSkills\TestSuite\Mock\MethodMocker;
use ArtSkills\TestSuite\Mock\PropertyAccess;
use Cake\I18n\Time;

class GitBranchTrimTest extends AppTestCase
{
	/**
	 * Тест того, что вызывалось при запуске
	 */
	public function test() {
		$git = Git::getInstance();

		$branchBefore = $git->getCurrentBranchName();

		$history = [];
		$this->_mockExecute($history);

		GitBranchTrim::run();

		self::assertEquals($branchBefore, $git->getCurrentBranchName(), 'Ветка не вернулась обратно');

		$deleteDateFrom = Time::now(PropertyAccess::getStatic(GitBranchTrim::class, '_branchDeleteInterval'))->format('Y-m-d');
		$skipBranches = [Git::BRANCH_NAME_MASTER, Git::BRANCH_NAME_HEAD, $branchBefore];

		$actualHistory = $history;
		$expectedHistory = [];
		if ($branchBefore != Git::BRANCH_NAME_MASTER) {
			$expectedHistory[] = 'git branch -a';
			$expectedHistory[] = 'git checkout master';
		}
		$expectedHistory[] = 'git remote update --prune';
		foreach ([Git::BRANCH_TYPE_REMOTE, Git::BRANCH_TYPE_LOCAL] as $type) {
			$expectedHistory = array_merge($expectedHistory, $this->_getCommandListMerged($type));
			$mergedBranches = $git->getMergedBranches($type);
			foreach ($mergedBranches as $branchName => $lastCommitDate) {
				if (($lastCommitDate <= $deleteDateFrom) && !in_array($branchName, $skipBranches)) {
					$expectedHistory = array_merge($expectedHistory, $this->_getCommandListDelete($branchName, $type));
				}
			}
		}
		if ($branchBefore != Git::BRANCH_NAME_MASTER) {
			$expectedHistory[] = 'git branch -a';
			$expectedHistory[] = 'git checkout ' . $branchBefore;
		}

		self::assertEquals($expectedHistory, $actualHistory, 'Неправильный набор комманд');
	}

	/**
	 * Мокаем _execute в Git
	 *
	 * @param array $history
	 * @throws \Exception
	 */
	private function _mockExecute(&$history) {
		MethodMocker::mock(Git::class, '_execute')
			->willReturnAction(function ($args) use (&$history) {
				$history[] = $args[0];
				if (preg_match('/^git (branch( -[ar])?|for-each-ref.*)$/', $args[0])) {
					exec($args[0], $output);
					return $output;
				} else {
					return [];
				}
			});
	}

	/**
	 * Список комманд, использованных для получения списка веток
	 *
	 * @param string $type
	 * @return array|bool
	 */
	private function _getCommandListMerged($type) {
		$list = ['git pull'];
		if ($type == Git::BRANCH_TYPE_REMOTE) {
			$list[] = 'git for-each-ref --format="%(refname) %(authordate:short)" refs/remotes/origin --merged';
		} elseif ($type == Git::BRANCH_TYPE_LOCAL) {
			$list[] = 'git for-each-ref --format="%(refname) %(authordate:short)" refs/heads --merged';
		} else {
			return false;
		}
		return $list;
	}

	/**
	 * Список комманд, использованных для удаления ветки
	 *
	 * @param string $branchDelete
	 * @param string $type
	 * @return array|bool
	 */
	private function _getCommandListDelete($branchDelete, $type) {
		$list = $this->_getCommandListMerged($type);
		if (empty($list)) {
			return false;
		}
		$list[] = 'git pull';
		if ($type == Git::BRANCH_TYPE_REMOTE) {
			$list[] = 'git push origin --delete ' . $branchDelete;
		} elseif ($type == Git::BRANCH_TYPE_LOCAL) {
			$list[] = 'git branch ' . $branchDelete . ' -d';
		} else {
			return false;
		}
		return $list;
	}


}
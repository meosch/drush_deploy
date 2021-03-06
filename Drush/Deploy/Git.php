<?php

namespace Drush\Deploy;

class Git {
  // Sets the default command name for this SCM on your *local* machine.
  // Users may override this by setting the scm_command variable.
  public $default_command = "git";
  public $config;
  public $verbose = FALSE;

  function __construct($config) {
    $this->config = $config;
    $this->verbose = drush_get_context('DRUSH_VERBOSE') ? '' : ' -q';
  }

  /**
   * When referencing "head", use the branch we want to deploy or, by
   * default, Git's reference of HEAD (the latest changeset in the default
   * branch, usually called "master").
   *
   * @return string
   */
  function head() {
    return drush_get_option('branch', 'HEAD');
  }

  /**
   * @return string
   */
  function origin() {
    return drush_get_option('remote', 'origin');
  }

  /**
   * Performs a clone on the remote machine, then checkout on the branch
   * you want to deploy.
   *
   * @param $revision
   * @param $destination
   * @return string
   */
  function checkout($revision, $destination) {
    $remote = $this->origin();

    $args = array();
    if ($remote != 'origin') $args[] = "-o #{remote}";

    if ($depth = drush_get_option('git_shallow_clone', FALSE)) {
      $args[] = "--depth $depth";
    }

    $execute = array();
    $args_str = implode(' ', $args);
    $repo = $this->config->repository;
    $verbose = $this->verbose;
    $execute[] = "git clone $verbose $args_str $repo $destination";
    // checkout into a local branch rather than a detached HEAD
    $execute[] = "cd $destination && git checkout $verbose -b deploy $revision";

    if (drush_get_option('git_enable_submodules', FALSE)) {
      $execute[] = "git submodule $verbose init";
      $execute[] = "git submodule $verbose sync";
      $execute[] = "git submodule $verbose update --init --recursive";
    }

    $cmd = implode(' && ', $execute);
    return $cmd;
  }

  /**
   * Performs a clone on the remote machine
   *
   * @param $revision
   * @param $destination
   * @return string
   */
  function clone_only($revision, $destination) {
    $remote = $this->origin();

    $args = array();
    if ($remote != 'origin') $args[] = "-o #{remote}";

    if ($depth = drush_get_option('git_shallow_clone', FALSE)) {
      $args[] = "--depth $depth";
    }

    $args_str = implode(' ', $args);
    $repo = $this->config->repository;
    $verbose = $this->verbose;
    $cmd = "git clone $verbose $args_str -b $revision $repo $destination";

    return $cmd;
  }

  /**
   * Performs a pull in the Drupal root folder
   *
   * @param $revision
   * @param $destination
   * @return string
   */
  function pull($revision, $destination) {
    $remote = $this->origin();

    $args = array();
    if ($remote != 'origin') $args[] = "-o #{remote}";

    if ($depth = drush_get_option('git_shallow_clone', FALSE)) {
      $args[] = "--depth $depth";
    }

    $execute = array();
    $verbose = $this->verbose;
    $execute[] = "cd $destination && chmod ug+w -R . && git pull $verbose $remote $revision";

    if (drush_get_option('git_enable_submodules', FALSE)) {
      $execute[] = "git submodule $verbose init";
      $execute[] = "git submodule $verbose sync";
      $execute[] = "git submodule $verbose update --init --recursive";
    }

    $cmd = implode(' && ', $execute);
    return $cmd;
  }

  /**
   * An expensive export. Performs a checkout as above, then
   * removes the repo.
   *
   * @param $revision
   * @param $destination
   */
  function export($revision, $destination) {
    $this->checkout($revision, $destination) . " && rm -Rf " . $destination . "/.git";
  }

  /**
   * Merges the changes to 'head' since the last fetch, for remote_cache
   * deployment strategy
   *
   * @param $revision
   * @param $destination
   * @return string
   */
  function sync($revision, $destination) {
    $remote  = drush_get_option('remote', 'origin');

    $execute = array("cd $destination");

    // Use git-config to setup a remote tracking branches. Could use
    // git-remote but it complains when a remote of the same name already
    // exists, git-config will just silenty overwrite the setting every
    // time. This could cause wierd-ness in the remote cache if the url
    // changes between calls, but as long as the repositories are all
    // based from each other it should still work fine.
    if ($remote != 'origin') {
      $execute[] = "git config remote.$remote.url $this->repository";
      $execute[] = "git config remote.$remote.fetch +refs/heads/*:refs/remotes/$remote/*";
    }

    $verbose = $this->verbose;
    // since we're in a local branch already, just reset to specified revision rather than merge
    $execute[] = "git fetch $verbose $remote && git fetch --tags $verbose $remote && git reset $verbose --hard $revision";

    if (drush_get_option('git_enable_submodules', FALSE)) {
      $execute[] = "git submodule $verbose init";
      $execute[] = "git submodule $verbose sync";
      $execute[] = "git submodule $verbose update --init --recursive";
    }

    // Make sure there's nothing else lying around in the repository (for
    // example, a submodule that has subsequently been removed).
    $execute[] = "git clean $verbose -d -x -f";

    $cmd = implode(' && ', $execute);
    return $cmd;
  }

  /**
   * Getting the actual commit id, in case we were passed a tag
   * or partial sha or something - it will return the sha if you pass a sha, too
   *
   * @throws Exception
   * @param $revision
   * @param bool $local
   * @return null
   */
  function queryRevision($revision, $local = TRUE) {
    $repository = $local ? '.' : $this->config->repository;
    if (preg_match('/^[0-9a-f]{40}$/', $revision)) return $revision;
    $command = 'git ls-remote ' . $repository . ' ' . $revision;
    $revdata = $this->config->capture($command, 'local');

    $newrev = NULL;
    foreach ($revdata as $refs) {
      list($rev, $ref) = preg_split("/\s+/", $refs);
      if (preg_replace('/refs\\/.*?\//', '', $ref) == $revision) {
        $newrev = $rev;
        break;
      }
    }
    if (!preg_match('/^[0-9a-f]{40}$/', $newrev)) {
      throw new Exception("Unable to resolve revision for '$revision' on repository '$repository'. $newrev");
    }

    return $newrev;
  }

}

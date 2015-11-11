use GitWrapper\GitException;
        parent::tearDown();
    public function testGitApply()
    {
        $git = $this->getWorkingCopy();

        $patch = <<<PATCH
diff --git a/FileCreatedByPatch.txt b/FileCreatedByPatch.txt
new file mode 100644
index 0000000..dfe437b
--- /dev/null
+++ b/FileCreatedByPatch.txt
@@ -0,0 +1 @@
+contents

PATCH;
        file_put_contents(self::WORKING_DIR . '/patch.txt', $patch);
        $git->apply('patch.txt');
        $this->assertRegExp('@\?\?\\s+FileCreatedByPatch\\.txt@s', $git->getStatus());
        $this->assertEquals("contents\n", file_get_contents(self::WORKING_DIR . '/FileCreatedByPatch.txt'));
    }

    public function testGitClean()
    {
        $git = $this->getWorkingCopy();

        file_put_contents(self::WORKING_DIR . '/untracked.file', "untracked\n");

        $result = $git
            ->clean('-d', '-f')
        ;

        $this->assertSame($git, $result);
        $this->assertFileNotExists(self::WORKING_DIR . '/untracked.file');
    }

    public function testGitArchive()
    {
        $archiveName = uniqid().'.tar';
        $archivePath = '/tmp/'.$archiveName;
        $git = $this->getWorkingCopy();
        $output = (string) $git->archive('HEAD', array('o' => $archivePath));
        $this->assertEquals("", $output);
        $this->assertFileExists($archivePath);
    }

    /**
     * This tests an odd case where sometimes even though a command fails and an exception is thrown
     * the result of Process::getErrorOutput() is empty because the output is sent to STDOUT instead of STDERR. So
     * there's a code path in GitProcess::run() to check the output from Process::getErrorOutput() and if it's empty use
     * the result from Process::getOutput() instead
     */
    public function testGitPullErrorWithEmptyErrorOutput()
    {
        $git = $this->getWorkingCopy();

        try {
            $git->commit('Nothing to commit so generates an error / not error');
        } catch(GitException $exception) {
            $errorOutput = $exception->getMessage();
        }

        $this->assertTrue(strpos($errorOutput, "Your branch is up-to-date with 'origin/master'.") !== false);
    }


    public function testCommitWithAuthor()
    {
        $git = $this->getWorkingCopy();
        file_put_contents(self::WORKING_DIR . '/commit.txt', "created\n");

        $this->assertTrue($git->hasChanges());

        $git
            ->add('commit.txt')
            ->commit(array(
                'm' => 'Committed testing branch.',
                'a' => true,
                'author' => 'test <test@lol.com>'
            ))
        ;

        $output = (string) $git->log();
        $this->assertContains('Committed testing branch', $output);
        $this->assertContains('Author: test <test@lol.com>', $output);
    }

    public function testIsTracking()
    {
        $git = $this->getWorkingCopy();

        // The master branch is a remote tracking branch.
        $this->assertTrue($git->isTracking());

        // Create a new branch without pushing it, so it does not have a remote.
        $git->checkoutNewBranch('non-tracking-branch');
        $this->assertFalse($git->isTracking());
    }

    public function testIsUpToDate()
    {
        $git = $this->getWorkingCopy();

        // The default test branch is up-to-date with its remote.
        $git->checkout('test-branch');
        $this->assertTrue($git->isUpToDate());

        // If we create a new commit, we are still up-to-date.
        file_put_contents(self::WORKING_DIR . '/commit.txt', "created\n");
        $git
            ->add('commit.txt')
            ->commit(array(
                'm' => '1 commit ahead. Still up-to-date.',
                'a' => true,
            ))
        ;
        $this->assertTrue($git->isUpToDate());

        // Reset the branch to its first commit, so that it is 1 commit behind.
        $git->reset(
            'HEAD~2',
            array('hard' => true)
        );

        $this->assertFalse($git->isUpToDate());
    }

    public function testIsAhead()
    {
        $git = $this->getWorkingCopy();

        // The default master branch is not ahead of the remote.
        $this->assertFalse($git->isAhead());

        // Create a new commit, so that the branch is 1 commit ahead.
        file_put_contents(self::WORKING_DIR . '/commit.txt', "created\n");
        $git
            ->add('commit.txt')
            ->commit(array('m' => '1 commit ahead.'))
        ;

        $this->assertTrue($git->isAhead());
    }

    public function testIsBehind()
    {
        $git = $this->getWorkingCopy();

        // The default test branch is not behind the remote.
        $git->checkout('test-branch');
        $this->assertFalse($git->isBehind());

        // Reset the branch to its parent commit, so that it is 1 commit behind.
        $git->reset(
            'HEAD^',
            array('hard' => true)
        );

        $this->assertTrue($git->isBehind());
    }

    public function testNeedsMerge()
    {
        $git = $this->getWorkingCopy();

        // The default test branch does not need to be merged with the remote.
        $git->checkout('test-branch');
        $this->assertFalse($git->needsMerge());

        // Reset the branch to its parent commit, so that it is 1 commit behind.
        // This does not require the branches to be merged.
        $git->reset(
            'HEAD^',
            array('hard' => true)
        );
        $this->assertFalse($git->needsMerge());

        // Create a new commit, so that the branch is also 1 commit ahead. Now a
        // merge is needed.
        file_put_contents(self::WORKING_DIR . '/commit.txt', "created\n");
        $git
            ->add('commit.txt')
            ->commit(array('m' => '1 commit ahead.'))
        ;
        $this->assertTrue($git->needsMerge());

        // Merge the remote, so that we are no longer behind, but only ahead. A
        // merge should then no longer be needed.
        $git->merge('@{u}');
        $this->assertFalse($git->needsMerge());
    }
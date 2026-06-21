## Merge Conflict Research

This week I worked on researching merge conflicts and how to fix them because my branch workflow has not been the best so far. Last week I messed up by making my changes on staging instead of dev,and when I relaized I just keep workign on staging. I picked this topic to learn more, since branches are such a major topic.

Basically merge conflicts happen when Git cannot automatically decide which changes should stay. This usually happens when the same file is edited in two different branches. The conflict itself is not really the end of the world. It is Git stopping and asking to choose the correct version before the merge can finish. The main steps are to open the file, look at the conflict markers, choose what should stay, delete the extra conflict text, save the file, then commit the fix.

Using git pull before I start making changes to ake suyre everything is up to date. If I am on my feature branch, I should pull the newest work from dev first so my branch is not behind. That way, I can catch any conflicts early instead of waiting until the pull request. If Git shows a conflict during the pull, I would fix the file, save it, commit the resolved version, and then keep working. This helps avoid bigger merge problems later.

The biggest thing I learned is that I need to slow down and check what branch I am on before I start working. I also need to pull the newest changes more often and use smaller commits so my work is easier to track. Going forward, I want to make sure I do my actual development work on the correct feature branch, merge it into dev, and only move it to staging when it is ready to be reviewed.

## References

GitHub. (n.d.). Resolving a merge conflict on GitHub. GitHub Docs. 
    https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/addressing-merge-conflicts/resolving-a-merge-conflict-on-github

Atlassian. (n.d.). How to resolve merge conflicts in Git. Atlassian Git Tutorial. 
    https://www.atlassian.com/git/tutorials/using-branches/merge-conflicts

## Merge Conflict Research

This week I worked on researching merge conflicts and how to fix them because my branch workflow has not been the best so far. Last week I messed up by making my changes on staging instead of dev,and when I relaized I just keep workign on staging. I picked this topic to learn more, since branches are such a major topic.

Basically merge conflicts happen when Git cannot automatically decide which changes should stay. This usually happens when the same file is edited in two different branches. The conflict itself is not really the end of the world. It is Git stopping and asking to choose the correct version before the merge can finish. The main steps are to open the file, look at the conflict markers, choose what should stay, delete the extra conflict text, save the file, then commit the fix.

Using git pull before I start making changes to ake suyre everything is up to date. If I am on my feature branch, I should pull the newest work from dev first so my branch is not behind. That way, I can catch any conflicts early instead of waiting until the pull request. If Git shows a conflict during the pull, I would fix the file, save it, commit the resolved version, and then keep working. This helps avoid bigger merge problems later.

While working with branches this week, I ran into the merge commit message screen after running a Git pull or merge. At first it looked like an error, but it was really Git asking me to confirm the merge message before finishing the commit. I learned that when Vim opens this screen, I can press Esc, type :wq, and press Enter to save and continue. This helped me understand that some Git issues are not actual problems, but just steps in the merge process that I need to complete correctly.

Tn reguards to the 3.3 Compliance & Security the main areas I focused on were WordPress plugin security, copyright concerns with selling Formula 1 inspired merch, Elementor security, and server hosting safety. I also looked at adding a privacy policy or disclaimer page to the website so users know what information may be collected through analytics, WooCommerce checkout, contact forms, or cookies.


## References

GitHub. (n.d.). Resolving a merge conflict on GitHub. GitHub Docs. 
    https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/addressing-merge-conflicts/resolving-a-merge-conflict-on-github

Atlassian. (n.d.). How to resolve merge conflicts in Git. Atlassian Git Tutorial. 
    https://www.atlassian.com/git/tutorials/using-branches/merge-conflicts

Federal Trade Commission. (n.d.). Fair Information Practice Principles. 
    https://www.ftc.gov/
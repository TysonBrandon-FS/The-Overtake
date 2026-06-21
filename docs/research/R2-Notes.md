## Week 2 Research

This week I built the first version of my custom plugin Elementor GitHub Sync. I based it on a previous WordPress plugin structure I made for a live social media connector. That plugin already had a WordPress dashboard setup, so I used that as the starting point and updated the purpose for this project.

For Week 2, my research focused more on how the plugin settings should work inside the WordPress dashboard. Since I am uploading this plugin code to GitHub, I didnt want private information hard coded into the plugin files. That is why the dashboard has fields for the GitHub owner, repo name, branch, token, export folder, and commit message. These settings let the plugin be reused without putting private GitHub information directly into the code. The Personal Access Token field is important for security because it should be entered through WordPress instead of saved inside the plugin files.

I also looked at Elementor CLI because the plugin needs a way to export Elementor data before sending it to GitHub. The Elementor CLI documentation helped me understand how Elementor commands can work through WP CLI.
https://developers.elementor.com/docs/cli/



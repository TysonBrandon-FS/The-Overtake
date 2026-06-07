## Week 1 Research

This week I researched how to connect my WordPress project to GitHub and how to use GitHub as a tracking system for the assignment. I learned that WordPress is different from a normal code project because a lot of changes are stored in the database instead of regular files. Because of that, I need to track my work with commits, screenshots, exports, and documentation instead of expecting GitHub to see every WordPress change automatically. This made me come up with my, custom Elementor Code Output idea.

## Custom Plugin Output Idea

The plugin would create a changelog entry whenever I document an Elementor style or code change. The explanation would go first, then the previous code, then the updated code, and extra notes at the bottom.

### Output Example
Buttons on store page editing done on [change-date]

Code explanation

The button styling was updated on the store page. The previous version used white text and a red border. The updated version changes the text color to black and uses a blue border. This change affects the visual style of the store button and should be tracked because Elementor may store the live change in the database instead of a normal CSS file.

---Previous---

[button class or button ID] {
    color: white;
    border: 1px solid red;
}

---Updated---

[button class or button ID] {
    color: black;
    border: 1px solid blue;
}


I also researched the best way to track Elementor updates. Elementor saves many design and layout changes inside WordPress, so GitHub will not always show those edits as code changes. My plan is to export important Elementor templates, save screenshots of front end updates, keep notes in my docs folder, and use an activity log plugin to show what changed in the WordPress dashboard.
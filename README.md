## BBB dashboard

### What is this?
A simple dashboard to see the usage of one/many BBB server/s in meetings/user ratio

### What does it need?
Developed for Apache2 and PHP7.3+\
But it will also run on nginx if you apply correct settings in nginx config (see .htaccess).

### How to run?
Just upload this to a folder which matches a subdomain, open config.php.dist and modify to your needs, rename to config.php and you are done.\
And maybe, if you want some protection, add .htpasswd for simple authentification.\
The script itself does not display individual persons, but with BBBs shared secret you would already have access to that...

### Why use this?
It is a very simple overview of the current usage on a BBB server.\
Meetings, users in these meetings, and all users together.

Also there is a complete recordings overview, no matter if done by Greenlight or other tools.\
Default: *read* and *delete*.\
If you use [BBB video download](https://github.com/tilmanmoser/bbb-video-download) you can add *download* to config.php then there will also be a download button.

### Licensed as...?
If not otherwise seen in subfolders, all other files are licensed as **GNU AFFERO GENERAL PUBLIC LICENSE v3**.\
Why? **Because sharing is caring.**

### Support?
Sure, here on Github if there is a real bug (but be careful, only accepting bugs, not questions)\
Commercial: [osc - open source company](https://www.open-source-company.de) or ask your trusty PHP developer (it is not that hard).

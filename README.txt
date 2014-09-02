++FOR MAMP USERS --------------------------------------------------
1. make sure your php is pointing to the MAMP config:

open a terminal, run the command "which php". It should return the MAMP folder.

2. If not, add the following line to this file

~/.profile (on a mac)

export PATH=/Applications/MAMP/bin/php/php5.x.x/bin:$PATH

replace x.x with your latest version of php

3. Close the terminal and open a new one. Repeat step 1 on the new terminal.
--FOR MAMP USERS --------------------------------------------------

4. create an environment variable to specify your countup folder path (instructions for a mac): open a terminal, open your profile file (vim ~/.profile) press key "a" for insert, paste the following

export PROJ_HOME="/pathtoprojfolder/"

Please replace accordingly

press escape, :x to save and close the terminal. Open a new one and write 

echo $PROJ_HOME

the right path should be displayed. This will allow the git hook "post-merge" to automatically update local bd after a pull.
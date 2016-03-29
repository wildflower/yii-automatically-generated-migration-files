# Summary #

---

**This allows a distributed team to easily update the db locally and then distribute it's updates with the other developers automatically with the rest of the code via a versioning control system (I used git).**

## Further reading ##

---

  * ### **This tool exports and generates the full db in xml format and migration code (safeUp/safeDown) for:** ###

> - initial full db;

> - added/dropped tables, columns, and foreign keys (and fks related indexes);

> - updated column attributes

  * ### **the following modified column attributes are detected and exported:** ###

> - type, length, zerofill, allow null, default value

  * ### **Please note:** ###

> - indexes are not automatically exported;

> - new and dropped foreign keys generate or remove linked indexes automatically in the migration file if $GENERATE\_FK\_IDX is set to true

> - columns renamed are considered drop columns and add addd new columns;

> - column unsigned and comments are not automatically exported;

> - the foreign key name exported is not a match with the one from the db, but based on the namming convention

  * ### **Final notes:** ###

> - see the [Wiki](https://code.google.com/p/yii-automatically-generated-migration-files/wiki/Wiki) for run instructions

> - to automate the migration after a git pull, use this [code](https://code.google.com/p/yii-automatically-generated-migration-files/source/browse/post-merge) in your .git/hooks folder. Don't forget to read the [README file](https://code.google.com/p/yii-automatically-generated-migration-files/source/browse/README.txt)

> - please feel free to send me improvements or comments to this code as I know it's not perfect :)

> - this project was inspired by [this code from bmarston](https://gist.github.com/bmarston/5541632)
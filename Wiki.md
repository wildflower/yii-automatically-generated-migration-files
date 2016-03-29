# **Run instructions** #

---

> - save this [file](https://code.google.com/p/yii-automatically-generated-migration-files/source/browse/updateDbCommand.php) to your yii/protected/commands folder

> - in the terminal, go to your yii protected folder path and run the command:$ ./yiic updatedb

> - an initial dump of the bd will be generated to an xml file - $bdFile;

> - an initial migration file will be generated with the full bd and will have the suffix: $initialMigrationFileSuffix (see file)

> - the bd config file file will continuously be updated with every subsequent run of this tool

> - following updates to the bd will generate files in the format mYearMonthDay\_Timestamp_$GENERATED\_FILE\_PREFIX (see file)_

> - for each bd update, a migration file will be added to the $migrationsDir (see file)

> - if your team members already have the db in place, ask them to add the initial migration file to their migration table so that it doesn't drop their current db, ex: version: m140902\_092342\_initial, apply\_time: 1409649822. This is the table that Yii uses for managing the migrated files. So the steps woud be, you'd generate the initial db xml and migration file, commit to the server. Before they pull it, they should add the row with the info above. Hope it helps, otherwise, ask ;)

> - to automate the migration after a git pull, save the [post-merge file](https://code.google.com/p/yii-automatically-generated-migration-files/source/browse/post-merge) to your .git/hooks folder. Don't forget to read the [README file](https://code.google.com/p/yii-automatically-generated-migration-files/source/browse/README.txt)
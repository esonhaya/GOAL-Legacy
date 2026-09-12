# Core developer console

After installing Composer dependencies, run these commands from the repository root:

```sh
composer doctor
composer modules:list
composer core:self-check
composer config:inspect
composer logs:recent
composer time:self-check
```

The console registers commands through `CommandRegistry`, so future isolated simulation and inspection commands can be added without changing the console runner.

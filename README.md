# composer-merge-plugin

This free and open source MIT-licensed Composer plugin allows you to move or symlink individual package files into specified directories outside the vendor directory.

Some projects have specific requirements on the location of package files that cannot be resolved by the Composer autoloader. This often concerns resource files such as html, css or language files. Legacy projects might need to include php files from specific locations outside the vendor directory.

Examples:
 - A web project might require html and css resources to be moved to global /html and /css folders
 - An international project expects all language files to reside within a global /languages directory 

Instead of relying on a build system or generic task runner such as brunch, gulp or grunt on top of Composer to perform these deployment tasks, this plugin aims at extending the capabilities of Composer to perform such build automation.

## Usage

1. This plugin is not published on [Packagist](https://packagist.org/) yet. To add it to your Composer-based project, add the corresponding repository for it to your `composer.json`. Composer's documentation describes how to [work with repositories](https://getcomposer.org/doc/05-repositories.md#vcs).

2. Add the plugin to your `composer.json` as a dependency:

    ```javascript
    "require": {
        "viison/composer-merge-plugin": "*",
        ...
    }
    ```

3. Configure the merge-plugin via your `composer.json`:

    ```javascript
    "extra": {
        "merge-plugin": {
            "some/example-package": {
                "merge-patterns": [
                    {
                        "src": "~assets/css/([^/]+)~",
                        "dst": "css/\\1"
                    }
                ],
                "merge-strategy": "copy"
            }
        }
    }
    ```
    
    This example configuration will copy all files from `root-package/vendor/some/example-package/assets/css` to `root-package/assets/css`

## Merge pattens

A merge pattern is a regular expression matching paths within your specified package. Matching files and directories will then be symlinked or copied into your root package directory.

You can either give a single regular expression or spefify an `src` pattern and a `dst` replacement.

## Merge strategies

The merge plugin can either `symlink` or `copy` your package's files, depending on your chosen `merge-strategy`.

You can also specify `merge-strategy-dev` which will take precedence over `merge-strategy`.

## Examples

1. Copy all files from `root-package/vendor/some/example-package/assets/css` to `root-package/assets/css`

    ```javascript
    "extra": {
        "merge-plugin": {
           "some/example-package": {
               "merge-patterns": [
                  "~assets/css/([^/]+)~"
                ],
               "merge-strategy": "copy"
            }
        }
    }
    ```

2. Symlink all files from `root-package/vendor/some/example-package/assets/css` to `root-package/public/css`

    ```javascript
    "extra": {
        "merge-plugin": {
            "some/example-package": {
              	"merge-patterns": [
                    {
                        "src": "~assets/css/([^/]+)~",
                        "dst": "public/css/\\1"
                    }
                ],
                "merge-strategy": "symlink"
            }
        }
    }
    ```

## License

**composer-merge-plugin** is licensed under the MIT License - see the
[LICENSE](LICENSE) file for details.

Copyright 2016 VIISON GmbH <https://viison.com/>
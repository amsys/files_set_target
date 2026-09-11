# Package files_set_target for the Nextcloud App Store.
#
# The App Store tarball must hold the built js/ and a production vendor/.
# Both are gitignored, so a plain `git archive` ships a dead app.
#
#   make dist        build everything, then package  (use this by hand)
#   make appstore    package what is already built   (used by CI)

app_name = files_set_target
build_dir = $(CURDIR)/build
artifact_dir = $(build_dir)/artifacts
package_dir = $(artifact_dir)/$(app_name)
tarball = $(artifact_dir)/$(app_name).tar.gz

# Tracked paths that are for development only and stay out of the tarball.
dev_only = ^(src|tests|docs|screenshots|\.github)/|^(Makefile|composer\.lock|package\.json|package-lock\.json|phpunit\.xml|webpack\.config\.js|\.eslintrc\.json|\.php-cs-fixer\.dist\.php|\.gitignore)$$

.PHONY: all dev build dist appstore clean

all: dist

# Everything the tarball needs, in the state the tarball needs it.
build:
	composer install --no-dev --optimize-autoloader
	npm ci
	npm run build

dist: build appstore

# Package only. CI builds first, so this target must not touch vendor/ or js/:
# a `composer install` here would put the dev packages back into the tarball.
appstore:
	@test -f js/files_set_target-share-target.js || { echo 'js/ is not built. Run `npm run build` or `make dist`.'; exit 1; }
	@test -f vendor/autoload.php || { echo 'vendor/ is missing. Run `composer install --no-dev`.'; exit 1; }
	@! test -d vendor/phpunit || { echo 'vendor/ holds dev packages. Run `composer install --no-dev`.'; exit 1; }
	rm -rf $(build_dir)
	mkdir -p $(package_dir)
# The file list comes from git, never from the working tree: anything
# untracked or excluded (local notes, deploy scripts, caches) can then never
# reach a published tarball. js/ and vendor/ are the two build outputs, and
# they are gitignored, so they are added by hand.
	git ls-files -z \
		| grep -zEv '$(dev_only)' \
		| rsync -a --from0 --files-from=- ./ $(package_dir)/
	rsync -a --exclude='*.map' js/ $(package_dir)/js/
	rsync -a vendor/ $(package_dir)/vendor/
	tar -czf $(tarball) -C $(artifact_dir) $(app_name)
	@echo "built $(tarball)"

clean:
	rm -rf $(build_dir)

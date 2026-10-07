const gulp = require('gulp');
const { execFile } = require('child_process');
const path = require('path');
const { error } = require('console');

let running = false;

function runCommand(command, args, done) {
  execFile(command, args, (error, stdout, stderr) => {
    if (stdout) {
      process.stdout.write(stdout);
    }

    if (stderr) {
      process.stderr.write(stderr);
    }

    done(error || undefined);
  });
}

function phpcbf(file, done) {
  const relativePath = path.relative(process.cwd(), file);

  runCommand('./vendor/bin/phpcbf', [relativePath], done);
}

function phpcs(file, done) {
  const relativePath = path.relative(process.cwd(), file);

  runCommand('./vendor/bin/phpcs', [relativePath], done);
}

function phpstan(file, done) {
  const relativePath = path.relative(process.cwd(), file);

  runCommand('./vendor/bin/phpstan', ['analyse', relativePath], done);
}

function checkPhp(file) {
  if (running) {
    return;
  }

  running = true;

  phpcbf(file, (error) => {
    if (error) {
      running = false;
      return;
    }

    phpcs(file, (error) => {
      if (error) {
        running = false;
        return;
      }

      phpstan(file, (error) => {
        running = false;
      });
    });
  });
}

function prettier(file, done) {
    const relativePath = path.relative(process.cwd(), file);

    runCommand(
        './node_modules/.bin/prettier',
        [
            '--write',
            '--plugin=@prettier/plugin-php',
            '--parser=php',
            relativePath,
        ],
        done,
    );
}


function watch() {
    gulp
        .watch(['src/**/*.php', 'config/**/*.php', 'tests/**/*.php'], {
            ignoreInitial: true,
        })
        .on('change', (file) => {
            checkPhp(file);
        });
    
    gulp
        .watch(['views/**/*.php'], {
            ignoreInitial: true,
        })
        .on('change', (file) => {
            phpcbf(file, function (error) {
                if (error) {
                    return;
                }
            });
        });

    
}

exports.phpcbf = phpcbf;
exports.phpcs = phpcs;
exports.phpstan = phpstan;
exports.watch = watch;

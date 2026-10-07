const gulp = require('gulp');
const { execFile } = require('child_process');
const path = require('path');

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

function watch() {
  gulp
    .watch(['src/**/*.php', 'config/**/*.php', 'tests/**/*.php'], {
      ignoreInitial: true,
    })
    .on('change', (file) => {
      checkPhp(file);
    });
}

exports.phpcbf = phpcbf;
exports.phpcs = phpcs;
exports.phpstan = phpstan;
exports.watch = watch;

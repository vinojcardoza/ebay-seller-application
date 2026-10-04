const gulp = require('gulp');
const shell = require('gulp-shell');

const phpcbf = () => {
    return gulp
        .src(['src/**/*.php', 'config/**/*.php', 'tests/**/*.php'], {
            read: false,
        })
        .pipe(shell('composer run phpcbf'));
};

const watch = () => {
    gulp.watch(['src/**/*.php', 'config/**/*.php', 'tests/**/*.php'], phpcbf);
};

exports.phpcbf = phpcbf;
exports.watch = watch;

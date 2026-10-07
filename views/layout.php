<!DOCTYPE html>
<html>
    <head>
        <title><?php echo $this->e($title ?? 'Business App');?></title>
    </head>
    <body>
        <header>header</header>
        <?php echo $this->section("content");?>
        <footer>footer</footer>
    </body>
</html>
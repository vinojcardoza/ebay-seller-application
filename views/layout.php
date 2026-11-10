<!DOCTYPE html>
<html>
    <head>
        <title><?php echo $this->e($title ?? 'Business App');?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2.1.1/css/pico.classless.min.css"/>
    </head>
    <body>
        <header>
            <nav>
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="/products">Products</a></li>
                </ul>
            </nav>
        </header>
        <main>
            <?php echo $this->section("content");?>
        </main>
        <footer>footer</footer>
    </body>
</html>
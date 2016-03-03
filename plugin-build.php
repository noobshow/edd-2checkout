<?php
$dir = @$argv[1] ?: dirname( __FILE__ );

if ( file_exists( $dir ) ) {
	deleteDir( $dir );
}

echo "changed directory to $dir" . "\n";
exec( "git clone https://github.com/OmnipayWP/edd-2checkout.git $dir" );
echo "git clone completed." . "\n";
// current directory
echo getcwd() . "\n";
chdir( $dir );
// current directory
echo getcwd() . "\n";


function deleteDir( $path ) {
	if ( PHP_OS === 'Windows' ) {
		exec( "rd /s /q {$path}" );
	} else {
		exec( "rm -rf {$path}" );
	}
}

deleteDir( '.git' );
deleteDir( 'tests' );

echo ".git and tests folders deleted" . "\n";

exec( 'composer install --no-dev' );
echo "composer install completed." . "\n";

foreach (
	array(
		'.gitignore',
		'composer.json',
		'README.md',
		'plugin-build.php',
		'composer.lock',
		'edd-2checkout.zip',
	) as $file
) {
	@unlink( $file );
}

// move up directory
chdir( str_replace( DIRECTORY_SEPARATOR . basename( $dir ), '', $dir ) );
echo "Archiving file" . "\n";
exec( "7z a edd-2checkout.zip edd-2checkout/" );
echo "Zip archiving completed" . "\n";


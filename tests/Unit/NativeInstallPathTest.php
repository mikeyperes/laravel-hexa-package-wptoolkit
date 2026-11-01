<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use hexa_package_whm\Models\WhmServer;
use hexa_package_wptoolkit\Support\LocalShellConnection;
use hexa_package_wptoolkit\Services\Concerns\{RunsWpCliCommands,ManagesInstalls};
final class NativeInstallPathTest extends TestCase {
    public function test_home_relative_info_path_uses_the_full_inventory_path_for_the_exact_install():void {
        $h=new NativeInstallPathHarness;$h->info=['id'=>49,'path'=>'/public_html/staging'];$h->inventory=[['id'=>49,'siteUrl'=>'https://example.com/staging','fullPath'=>'/home/example/public_html/staging'],['id'=>50,'fullPath'=>'/home/other/public_html']];self::assertSame('/home/example/public_html/staging',$h->path());self::assertSame(2,$h->commands);self::assertSame('/home/example/public_html/staging',$h->path());self::assertSame(2,$h->commands);
    }
    public function test_full_path_shape_remains_a_single_read_and_unknown_ids_never_borrow_another_path():void {
        $h=new NativeInstallPathHarness;$h->info=['fullPath'=>'/home/example/public_html'];self::assertSame('/home/example/public_html',$h->path());self::assertSame(1,$h->commands);
        $h=new NativeInstallPathHarness;$h->info=['path'=>'/public_html'];$h->inventory=[['id'=>99,'fullPath'=>'/home/other/public_html']];self::assertNull($h->path());
    }
}
final class NativeInstallPathHarness {
    use RunsWpCliCommands,ManagesInstalls;
    public array $info=[];public array $inventory=[];public int $commands=0;protected array $installInfoCache=[];protected object $generic;
    public function __construct(){$this->generic=new class {public function log(...$args):void{}};}
    public function path():?string{$s=new WhmServer;$s->id=713;return $this->resolveInstallPath($s,new LocalShellConnection,49);}
    protected function shellBinary(...$args):string{return 'wp-toolkit';}
    protected function runCommandWithExitCode($connection,string $command):array{$this->commands++;$out=json_encode(str_contains($command,'--info')?$this->info:$this->inventory);return ['exit_code'=>0,'clean_output'=>$out,'raw_output'=>$out];}
}

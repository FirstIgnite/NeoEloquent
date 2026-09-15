<?php

namespace Vinelab\NeoEloquent\Console\Migrations;

use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Migrations\Migrator;

class MigrateCommand extends BaseCommand
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'neo4j:migrate {--database= : The database connection to use.}
                {--force : Force the operation to run when in production.}
                {--path= : The path of migrations files to be executed.}
                {--pretend : Dump the SQL queries that would be run.}
                {--seed : Indicates if the seed task should be re-run.}
                {--step : Force the migrations to be run so they can be rolled back individually.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the database migrations';

    /**
     * The migrator instance.
     *
     * @var \Illuminate\Database\Migrations\Migrator
     */
    protected $migrator;

    /**
     * @param \Illuminate\Database\Migrations\Migrator $migrator
     *
     * @return void
     */
    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->confirmToProceed()) {
            return;
        }

        $this->migrator->setConnection($this->option('database'));

        // Give the migrator the output BEFORE it runs. Modern Illuminate
        // Migrators write progress as each migration is applied, straight to
        // the OutputInterface they were handed, rather than collecting notes
        // to be drained afterwards.
        //
        // This used to run AFTER ->run() and iterate the return value of
        // setOutput():
        //
        //     foreach ($this->migrator->setOutput($this->output) as $note) {
        //         $this->output->writeln($note);
        //     }
        //
        // which is broken two ways and fails SILENTLY, so the command exited 0
        // having printed nothing at all. The output was attached only after
        // every migration had already run, so the migrator had nowhere to write
        // during the run; and setOutput() returns the Migrator, so foreach
        // iterated an object's *public properties*, of which Migrator has none.
        // No notes, no error, no clue whether it applied ten migrations or zero.
        $this->migrator->setOutput($this->output);

        // Next, we will check to see if a path option has been defined. If it has
        // we will use the path relative to the root of this installation folder
        // so that migrations may be run for any path within the applications.
        $this->migrator->run($this->getMigrationPaths(), [
            'pretend' => $this->option('pretend'),
            'step'    => $this->option('step'),
        ]);
        // Finally, if the "seed" option has been given, we will re-run the database
        // seed task to re-populate the database, which is convenient when adding
        // a migration and a seed at the same time, as it is only this command.
        if ($this->option('seed')) {
            $this->call('db:seed', ['--force' => true]);
        }
    }
}

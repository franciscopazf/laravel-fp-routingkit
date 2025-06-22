<?php

namespace FPJ\RoutingKit\Commands;

use FPJ\RoutingKit\Entities\FPJNavigation;
use FPJ\RoutingKit\Entities\FPJRoute;
use FPJ\RoutingKit\Features\InteractiveFeature\FPJInteractiveNavigator;
use Illuminate\Console\Command;
use function Laravel\Prompts\select;



class FPJRouteCommand extends Command
{
    // variables necesarias (opcionales)
    protected $signature = 'fpj:route 
                            {--delete : Eliminar una ruta existente} 
                            {--rewrite : reescribe todos los archivos de rutas (futuro)}
                            {--new : Crear una nueva ruta (futuro)}
                            {--id= : ID de la ruta a procesar} 
                            {--parentId= : ID del padre (opcional)}';

    protected $description = 'Comando para gestionar rutas FPJRoutingKit';

    protected FPJInteractiveNavigator $interactive;

    public function handle()
    {

        $this->interactive = FPJInteractiveNavigator::make(FPJRoute::class);

        // --delete, --new, --move
        if ($this->option('delete')) {
            $this->interactive->eliminar($this->option('id'));
            return;
        }

        if ($this->option('new')) {
            // id 
            $data['id'] = $this->option('id');
            // parentId
            $data['parentId'] = $this->option('parentId');
            $this->crearRuta($data);
            return;
        }
        if ($this->option('rewrite')) {
            $this->interactive->reescribir();
            return;
        }

        $this->menuInteractivo();
         $this->info('Exito, la operación se ha completado correctamente.');
    }

    protected function menuInteractivo()
    {
        $opcion = select(
            label: 'Selecciona una opción',
            options: [
                'nueva' => '🛠️ Crear nueva ruta',
                'eliminar' => '🗑️ Eliminar ruta existente',
                'reescribir' => '🔄 Reescribir rutas',
                'salir' => '🚪 Salir',
            ]
        );

        match ($opcion) {
            'nueva' => $this->crearRuta(),
            'eliminar' => $this->interactive->eliminar(),
            'reescribir' => $this->interactive->reescribir(),
            'salir' => $this->info('Saliendo...'),
        };
    }

    protected function crearRuta(array $data = [])
    {
        $route = $this->interactive->crear($data);
        // preguntar si se quiere crear una navegación
        $crearNavegacion = select(
            label: '¿Deseas crear una navegación para esta ruta?',
            options: [
                'si' => 'Sí, crear navegación',
                'no' => 'No, solo crear ruta',
            ]
        );
        if ($crearNavegacion === 'si') {
           $navegacion = FPJInteractiveNavigator::make(FPJNavigation::class)
                ->crear(data:[
                    'instanceRouteId' => $route->id,
                    'id' => $route->id,
                ]);
        }
        return $data;
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EntradaResource\Pages;
use App\Filament\Resources\EntradaResource\RelationManagers;
use App\Models\Entrada;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput; // Para campos de texto
use Filament\Forms\Components\Textarea; // Para descripciones multilínea
use Filament\Forms\Components\DateTimePicker; // Para campos de fecha y hora
use Filament\Forms\Components\Checkbox; // Ya lo tienes, pero para recordar
use Filament\Forms\Components\Hidden; // Ya lo tienes, pero para recordar
use Filament\Forms\Components\Select; // Si en algún momento necesitas un selector de eventos en el form principal

class EntradaResource extends Resource
{
    protected static ?string $model = Entrada::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    // Desactivar navegacion lateral del recurso Entradas
    protected static bool $shouldRegisterNavigation = false;
    // no se que es esta linea
    //protected static ?string $navigationGroup = 'Gestión de Eventos'; // Si usas grupos, lo agregamos aquí.

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->required()
                    ->maxLength(255)
                    ->label('Nombre de la Entrada') // Añadimos labels explícitos
                    ->placeholder('Ej: Entrada General, VIP, Early Bird'),

                Forms\Components\Textarea::make('descripcion')
                    ->rows(3)
                    ->maxLength(500) // Usas 500, en el modelo anterior usaba 65535, ajusta según tu DB
                    ->columnSpanFull() // Ocupa todo el ancho si lo necesitas
                    ->label('Descripción'),

                Forms\Components\TextInput::make('stock_inicial')
                    ->numeric()
                    ->required()
                    ->minValue(0) // Puede ser 0 si el stock es ilimitado o se agrega después
                    ->label('Stock Inicial (Cantidad total disponible)'),

                Forms\Components\TextInput::make('stock_actual')
                    ->numeric()
                    // Si 'stock_actual' se inicializa en el 'creating' del modelo, hacerlo readOnly aquí
                    ->readOnly()
                    ->label('Stock Actual (Cantidad restante para vender)'),

                Forms\Components\TextInput::make('max_por_compra')
                    ->numeric()
                    ->nullable() // Cambiado a nullable si no es un campo obligatorio
                    ->minValue(1)
                    ->label('Máximo por Compra')
                    ->placeholder('Dejar vacío para ilimitado por compra'),

                Forms\Components\TextInput::make('precio')
                    ->numeric()
                    ->required()
                    ->step(0.01) // Para permitir decimales en el precio
                    ->prefix('ARS$') // Añadimos un prefijo de moneda
                    ->label('Precio'),

                Forms\Components\Checkbox::make('valido_todo_el_evento')
                    ->label('Este producto es válido para cualquier día del evento'),

                // --- Tus campos de FECHA DE DISPONIBILIDAD ---
                Forms\Components\DateTimePicker::make('disponible_desde')
                    ->seconds(false)
                    ->label('Disponible Desde')
                    ->nullable(), // Permite que sea nulo si no hay fecha de inicio

                Forms\Components\DateTimePicker::make('disponible_hasta')
                    ->label('Disponible Hasta')
                    ->nullable(), // Permite que sea nulo si no hay fecha de fin

                Forms\Components\Hidden::make('evento_id')
                    ->default(fn() => request()->get('evento_id'))
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre'),
                TextColumn::make('precio')->label('Precio')->money('ARS'),
                // --- Columnas de STOCK en la tabla ---
                TextColumn::make('stock_inicial')->label('Stock Inicial'),
                TextColumn::make('stock_actual')->label('Stock Actual'),
                TextColumn::make('max_por_compra')->label('Máx. x Compra'),

                // --- Columnas de FECHA DE DISPONIBILIDAD ---
                TextColumn::make('disponible_desde')->dateTime()->label('Desde'),
                TextColumn::make('disponible_hasta')->dateTime()->label('Hasta'),
                // --------------------------------------------

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true) // Oculto por defecto
                    ->label('Creado el'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Aquí podrás añadir Relation Managers, por ejemplo, para ver las PurchasedTickets asociadas a esta Entrada
            // RelationManagers\PurchasedTicketsRelationManager::class, // Lo veremos en una fase posterior
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntradas::route('/'),
            'create' => Pages\CreateEntrada::route('/create'),
            'edit' => Pages\EditEntrada::route('/{record}/edit'),
        ];
    }

    protected function getTableQuery()
    {
        $query = parent::getTableQuery();

        if ($eventoId = request()->get('evento_id')) {
            $query->where('evento_id', $eventoId);
        }

        // --- AÑADIMOS LA LÓGICA DE RESTRICCIÓN DE ACCESO (solo si aún no la tenías aquí) ---
        $user = auth()->user();
        if ($user && $user->hasRole('admin')) { // Asumiendo que usas Spatie/Laravel-Permission o similar
            return $query; // Admins ven todo
        }

        if ($user) {
            return $query->whereHas('evento', function (Builder $eventoQuery) use ($user) {
                $eventoQuery->where('organizador_id', $user->id);
            });
        }

        // Si no hay usuario o no es productor, no mostrar nada
        return $query->where('id', null);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('evento', function ($query) {
                $query->where('organizador_id', auth()->id());
            });
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $evento = \App\Models\Evento::find($data['evento_id'] ?? null);

        if (!$evento || $evento->organizador_id !== auth()->id()) {
            abort(403, 'No estás autorizado para crear entradas en este evento.');
        }

        return $data;
    }


    public static function mutateFormDataBeforeSave(array $data): array
    {
        $evento = \App\Models\Evento::find($data['evento_id'] ?? null);

        if (!$evento || $evento->organizador_id !== auth()->id()) {
            abort(403, 'No estás autorizado para modificar entradas en este evento.');
        }

        return $data;
    }
}

<?php

namespace App\Exports;

use App\Models\Confirmando;
use App\Models\Grupo;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ConfirmandosPorGruposExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $sheets = [];
        $grupos = Grupo::with(['catequistas', 'confirmandos.apoderados'])->get();

        foreach ($grupos as $grupo) {
            $sheets[] = new ConfirmandosPorGrupoSheet($grupo);
        }

        $sheets[] = new ConfirmandosPorGrupoSheet(null);

        return $sheets;
    }
}

class ConfirmandosPorGrupoSheet implements 
    FromCollection, 
    WithHeadings, 
    WithMapping, 
    WithTitle, 
    WithEvents, 
    WithCustomStartCell
{
    private $grupo;
    private $rowIndex = 0;

    public function __construct($grupo)
    {
        $this->grupo = $grupo;
    }

    public function collection()
    {
        $query = Confirmando::with('apoderados');
        
        if ($this->grupo) {
            $query->where('grupo_id', $this->grupo->id);
        } else {
            $query->whereNull('grupo_id');
        }

        return $query->orderBy('apellidos', 'asc')->get();
    }

    public function title(): string
    {
        return $this->grupo ? $this->grupo->nombre : 'Sin Grupo';
    }

    public function startCell(): string
    {
        return 'A5';
    }

    public function headings(): array
    {
        // Según la imagen: N°, APELLIDOS, NOMBRES, CELULAR, CUMPLEAÑOS, DOMICILIO, APODERADO, TIPO APODERADO, CELULAR
        return [
            'N°',
            'APELLIDOS',
            'NOMBRES',
            'CELULAR',
            'CUMPLEAÑOS',
            'DOMICILIO',
            'APODERADO',
            'TIPO APODERADO',
            'CELULAR',
        ];
    }

    public function map($confirmando): array
    {
        $this->rowIndex++;
        
        // Tomamos el primer apoderado si existe
        $apoderado = $confirmando->apoderados->first();

        return [
            $this->rowIndex,
            mb_strtoupper($confirmando->apellidos),
            mb_strtoupper($confirmando->nombres),
            $confirmando->celular,
            $confirmando->fecha_nacimiento, // "CUMPLEAÑOS"
            '', // DOMICILIO (Vacío si no lo tienes en BD)
            $apoderado ? mb_strtoupper($apoderado->apellidos . ' ' . $apoderado->nombres) : '',
            $apoderado ? mb_strtoupper($apoderado->pivot->tipo_apoderado_id) : '', // Ajustar según tu relación
            $apoderado ? $apoderado->celular : '',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $this->rowIndex + 5; // Fila inicial (5) + cantidad de registros

                // 1. Estilos de Encabezados Superiores (Filas 2 y 3)
                $sheet->mergeCells('A2:I2');
                $sheet->mergeCells('A3:I3');
                
                $nombreGrupo = $this->grupo ? $this->grupo->nombre : 'SIN GRUPO';
                $sheet->setCellValue('A2', "Grupo: " . $nombreGrupo);
                
                $catequistas = $this->grupo && $this->grupo->catequistas 
                    ? $this->grupo->catequistas->pluck('nombres')->implode(', ') 
                    : '';
                $sheet->setCellValue('A3', "Catequistas: " . mb_strtoupper($catequistas));

                $sheet->getStyle('A2:A3')->getFont()->setBold(true)->setSize(12);

                // 2. Formato de la Tabla (Encabezados en Fila 5)
                $tableRange = 'A5:I' . $lastRow;
                $headerRange = 'A5:I5';

                // Bordes para toda la tabla
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Alineación centrada para N°, Celulares y Cumpleaños
                $sheet->getStyle('A5:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('D5:E' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('I5:I' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Negrita para los encabezados de la tabla
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // 3. Auto-ajuste de columnas
                foreach (range('A', 'I') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            },
        ];
    }
}
// Fase 5 · Edge Function `exportar-confirmandos`
//
// Reemplaza ConfirmandoController::exportarExcel + ConfirmandosPorGruposExport.
// Toma los datos ya agrupados de fn_export_confirmandos y arma el libro .xlsx con
// una hoja por grupo (+ "Sin Grupo"): cabecera con grupo y catequistas, tabla
// con bordes desde A5, columnas N°/APELLIDOS/NOMBRES/CELULAR/CUMPLEAÑOS/DOMICILIO/
// APODERADO/TIPO APODERADO/CELULAR.
//
// GET → descarga binaria (application/vnd.openxmlformats...sheet).

import { createClient } from "npm:@supabase/supabase-js@2";
import ExcelJS from "npm:exceljs@4.4.0";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;

const CORS = {
  "Access-Control-Allow-Origin": Deno.env.get("EXPORTAR_CONFIRMANDOS_ALLOWED_ORIGIN") ?? "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
};
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { ...CORS, "Content-Type": "application/json" } });

const HEADERS = ["N°", "APELLIDOS", "NOMBRES", "CELULAR", "CUMPLEAÑOS", "DOMICILIO", "APODERADO", "TIPO APODERADO", "CELULAR"];
const up = (s: string | null | undefined) => (s ?? "").toString().toUpperCase();

type Fila = {
  apellidos: string; nombres: string; celular: string | null; fecha_nacimiento: string | null;
  apoderado: { apellidos: string; nombres: string; celular: string | null; tipo: string | null } | null;
};
type Grupo = { nombre: string; catequistas: string[]; confirmandos: Fila[] };

function sheetName(raw: string, used: Set<string>): string {
  let n = (raw || "Hoja").replace(/[\[\]:*?/\\]/g, " ").trim().slice(0, 28) || "Hoja";
  let cand = n, i = 2;
  while (used.has(cand.toLowerCase())) cand = `${n} ${i++}`;
  used.add(cand.toLowerCase());
  return cand;
}

function addSheet(wb: ExcelJS.Workbook, titulo: string, catequistas: string[], filas: Fila[], used: Set<string>) {
  const ws = wb.addWorksheet(sheetName(titulo, used));

  ws.mergeCells("A2:I2");
  ws.getCell("A2").value = `Grupo: ${titulo}`;
  ws.mergeCells("A3:I3");
  ws.getCell("A3").value = `Catequistas: ${up(catequistas.join(", "))}`;
  ws.getCell("A2").font = ws.getCell("A3").font = { bold: true, size: 12 };

  HEADERS.forEach((h, i) => {
    const c = ws.getCell(5, i + 1);
    c.value = h;
    c.font = { bold: true };
    c.alignment = { horizontal: "center" };
  });

  const widths = HEADERS.map((h) => h.length);
  filas.forEach((f, idx) => {
    const ap = f.apoderado;
    const fila = [
      idx + 1, up(f.apellidos), up(f.nombres), f.celular ?? "",
      f.fecha_nacimiento ?? "", "",
      ap ? up(`${ap.apellidos} ${ap.nombres}`) : "",
      ap ? up(ap.tipo) : "",
      ap ? (ap.celular ?? "") : "",
    ];
    const r = ws.getRow(6 + idx);
    fila.forEach((v, i) => {
      r.getCell(i + 1).value = v as ExcelJS.CellValue;
      widths[i] = Math.max(widths[i], String(v).length);
    });
  });

  const lastRow = 5 + filas.length;
  const thin = { style: "thin" as const };
  for (let row = 5; row <= lastRow; row++) {
    for (let col = 1; col <= 9; col++) {
      ws.getCell(row, col).border = { top: thin, left: thin, bottom: thin, right: thin };
    }
  }
  [1, 4, 5, 9].forEach((col) => {
    for (let row = 5; row <= lastRow; row++) ws.getCell(row, col).alignment = { horizontal: "center" };
  });
  ws.columns.forEach((c, i) => { c.width = Math.min(Math.max(widths[i] + 2, 8), 40); });
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: CORS });
  if (req.method !== "GET" && req.method !== "POST") return json({ message: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization") ?? "";
  if (!authHeader.startsWith("Bearer ")) return json({ message: "No autenticado." }, 401);

  const caller = createClient(SUPABASE_URL, ANON_KEY, { global: { headers: { Authorization: authHeader } } });
  const { data: userData, error: userErr } = await caller.auth.getUser();
  if (userErr || !userData?.user) return json({ message: "Sesión no válida." }, 401);

  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { autoRefreshToken: false, persistSession: false } });
  const { data, error } = await admin.rpc("fn_export_confirmandos", { p_actor_auth: userData.user.id });
  if (error) {
    return json({ message: error.message }, error.code === "42501" ? 403 : 400);
  }

  const payload = data as { grupos: Grupo[]; sin_grupo: Fila[] };
  const wb = new ExcelJS.Workbook();
  const used = new Set<string>();
  for (const g of payload.grupos) addSheet(wb, g.nombre, g.catequistas ?? [], g.confirmandos ?? [], used);
  addSheet(wb, "SIN GRUPO", [], payload.sin_grupo ?? [], used);
  if (wb.worksheets.length === 0) addSheet(wb, "SIN GRUPO", [], [], used);

  const buf = await wb.xlsx.writeBuffer();
  return new Response(buf, {
    headers: {
      ...CORS,
      "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "Content-Disposition": 'attachment; filename="Confirmandos_por_Grupos.xlsx"',
    },
  });
});

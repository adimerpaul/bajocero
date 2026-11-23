import 'package:flutter/material.dart';

/// Paleta de Bajo Cero: rojo de marca sobre fondo claro. Los colores de marca y
/// de estado son los mismos del panel web (`front/src/css/quasar.variables.scss`).
class AppColores {
  static const primario = Color(0xFFC62828);
  static const primarioOscuro = Color(0xFF8E1B1B);
  static const primarioSuave = Color(0xFFFFEBEE);

  /// Fondo de la pantalla de arranque (mismo rojo que el splash nativo).
  static const arranque = Color(0xFFC62828);
  static const fondo = Color(0xFFF4F6F8);
  static const texto = Color(0xFF1B2733);
  static const textoSuave = Color(0xFF7A8894);
  static const borde = Color(0xFFE3E8EC);
  static const rojo = Color(0xFFD32F2F);
  static const ambar = Color(0xFFE8A33D);

  /// Tipos de pago, con los mismos colores que las tablas del panel web:
  /// efectivo en verde, QR en azul.
  static const verde = Color(0xFF21BA45);
  static const azul = Color(0xFF2196F3);
}

ThemeData construirTema() {
  final base = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: AppColores.primario,
      primary: AppColores.primario,
      brightness: Brightness.light,
    ),
    scaffoldBackgroundColor: AppColores.fondo,
  );

  return base.copyWith(
    appBarTheme: const AppBarTheme(
      backgroundColor: Colors.white,
      foregroundColor: AppColores.texto,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(color: AppColores.texto, fontSize: 18, fontWeight: FontWeight.w700),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: AppColores.borde),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: AppColores.borde),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: AppColores.primario, width: 1.6),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: AppColores.primario,
        foregroundColor: Colors.white,
        elevation: 0,
        padding: const EdgeInsets.symmetric(vertical: 16),
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(foregroundColor: AppColores.primario),
    ),
    dividerTheme: const DividerThemeData(color: AppColores.borde, space: 1, thickness: 1),
  );
}

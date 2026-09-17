<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Impact Score API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist/swagger-ui.css">
  <style>body { margin: 0; padding: 0; }</style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist/swagger-ui-bundle.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/js-yaml@3.14.1/dist/js-yaml.min.js"></script>
  <script>
    const spec = `openapi: 3.0.3
info:
  title: Impact Score API
  version: 1.0.0
  description: >
    This API provides access to program-level scores, summary statistics, and impact reporting for the Impact Score App.
servers:
  - url: https://yourdomain.com/api
paths:
  /programs.php:
    get:
      summary: Get list of programs
      parameters:
        - in: query
          name: team
          schema: { type: string }
        - in: query
          name: start_date
          schema: { type: string, format: date }
        - in: query
          name: end_date
          schema: { type: string, format: date }
        - in: query
          name: id
          schema: { type: integer }
      responses:
        '200':
          description: List of program records or a single program
          content:
            application/json:
              schema:
                oneOf:
                  - type: array
                    items:
                      $ref: '#/components/schemas/Program'
                  - $ref: '#/components/schemas/Program'
  /scores.php:
    get:
      summary: Get list of score records
      parameters:
        - in: query
          name: user_id
          schema: { type: integer }
        - in: query
          name: team
          schema: { type: string }
        - in: query
          name: program
          schema: { type: string }
        - in: query
          name: start_date
          schema: { type: string, format: date }
        - in: query
          name: end_date
          schema: { type: string, format: date }
        - in: query
          name: limit
          schema: { type: string }
      responses:
        '200':
          description: List of score entries
          content:
            application/json:
              schema:
                type: array
                items:
                  $ref: '#/components/schemas/Score'
  /summary.php:
    get:
      summary: Get impact summary statistics
      parameters:
        - in: query
          name: start_date
          schema: { type: string, format: date }
        - in: query
          name: end_date
          schema: { type: string, format: date }
      responses:
        '200':
          description: Summary dashboard metrics
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/Summary'
components:
  schemas:
    Program:
      type: object
      properties:
        id: { type: integer }
        name: { type: string }
        team: { type: string }
        program_name: { type: string }
        total_score: { type: integer }
        date: { type: string, format: date }
        attendance: { type: integer }
        scaled_attendance: { type: number, format: float }
        adjusted_impact_score: { type: number, format: float }
        created_at: { type: string, format: date-time }
        updated_at: { type: string, format: date-time }
    Score:
      type: object
      properties:
        id: { type: integer }
        user_name: { type: string }
        team_name: { type: string }
        program: { type: string }
        total_score: { type: integer }
        program_date: { type: string, format: date }
        attendance: { type: integer }
        scaled_attendance: { type: number, format: float }
        adjusted_impact_score: { type: number, format: float }
        submission_date: { type: string, format: date-time }
    Summary:
      type: object
      properties:
        average_impact_scores:
          type: object
          properties:
            youth: { type: number }
            adult: { type: number }
        highest_impact_scores:
          type: object
          properties:
            youth:
              type: object
              properties:
                program: { type: string }
                score: { type: number }
                attendees: { type: integer }
            adult:
              type: object
              properties:
                program: { type: string }
                score: { type: number }
                attendees: { type: integer }
        highest_attendance:
          type: object
          properties:
            youth:
              type: object
              properties:
                program: { type: string }
                attendees: { type: integer }
            adult:
              type: object
              properties:
                program: { type: string }
                attendees: { type: integer }
        program_stats:
          type: object
          properties:
            total_programs: { type: integer }
            total_attendees: { type: integer }
            adult_services:
              type: object
              properties:
                programs: { type: integer }
                attendees: { type: integer }
            youth_services:
              type: object
              properties:
                programs: { type: integer }
                attendees: { type: integer }
            one_on_ones:
              type: object
              properties:
                programs: { type: integer }
                attendees: { type: integer }
        date_range:
          type: object
          properties:
            start: { type: string, format: date }
            end: { type: string, format: date }`;
    window.onload = function () {
      const ui = SwaggerUIBundle({
        spec: jsyaml.load(spec),
        dom_id: '#swagger-ui',
        presets: [SwaggerUIBundle.presets.apis],
        layout: "BaseLayout"
      });
    };
  </script>
</body>
</html>

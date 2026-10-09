# Nico — How It Works

## Components

- **Frontend (Web / Blog / PWA)** — where users log in, upload resources, and view generated content.
- **File Management System** — stores uploaded files, links them to resources, and lists them in the UI.
- **Job Queue** — holds pending work units waiting to be processed.
- **Mac Worker** — the compute server that does all the heavy processing.
- **Result Store** — holds the extracted text, knowledge, and generated content.

## Big Picture

The cPanel host runs the user-facing side: upload, file management, the job queue, and the final content. The Mac worker does the actual processing: reading files, understanding them, and generating quizzes, lessons, and learning materials. The two talk through the job queue.

```
User → Upload → File Management System → Job Queue
                                             │
                                             ▼
                                        Mac Worker
                                             │
                                             ▼
                                       Result Store
                                             │
                                             ▼
                                  Frontend (Web / Blog / PWA)
```

## End-to-End Flow

### 1. Upload
1. A user uploads a resource (PDF, document, image, audio, video, plain text, or a link) from the frontend.
2. The file is stored and registered in the File Management System.
3. The File Management System lists the file in the UI and links it to a resource.

### 2. Queue
4. A processing job for the resource is added to the Job Queue.

### 3. Ingestion (Mac Worker)
5. The Mac worker picks up the job.
6. It reads the source file and extracts what it can:
   - OCR for images and scans.
   - Parsing for PDFs and documents.
   - Transcription for audio and video.
   - Direct reading for plain text and links.

### 4. Knowledge Modelling (Mac Worker)
7. The extracted content is broken into pieces and understood.
8. The understanding is stored so it can be reused for generation.

### 5. Content Generation (Mac Worker)
9. From the knowledge, the worker generates:
   - Quizzes
   - Lessons
   - Learning materials

### 6. Results
10. The generated content and extracted data are saved to the Result Store.

### 7. Delivery
11. The frontend reads from the Result Store and displays the generated quizzes, lessons, and materials.
12. The same content is delivered across Web, Blog, and PWA.

## Parallel Flow — File Management

Uploaded files follow their own path alongside processing:

```
Raw File → Upload → File Management System → displayed in Frontend UI
```

- Files are shown in the UI as soon as they are uploaded.
- Processing happens behind the scenes and attaches generated content to those files.

## Status Tracking

- Each resource moves through stages: uploaded → queued → ingesting → modelling → generating → done.
- The frontend polls the status and shows progress to the user.
- Failed stages are retried; repeatedly failing resources are marked for attention.

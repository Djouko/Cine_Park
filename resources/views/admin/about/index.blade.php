@extends('admin.layouts.master')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>A propos</h1>
        </div>

        <div class="card card-primary">
            <div class="card-header">
                <h4>Modifier A propos</h4>

            </div>
            <div class="card-body">
                <form action="{{ route('admin.about.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="form-group">
                        <label>Image</label>
                        <div id="image-preview" class="image-preview">
                            <label for="image-upload" id="image-label">Choisir le fichier</label>
                            <input type="file" name="image" id="image-upload" />
                            <input type="hidden" name="old_image" value="{{ @$about->image }}" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Titre</label>
                        <input type="text" name="title" class="form-control" value="{{ @$about->title }}">
                    </div>
                    <div class="form-group">
                        <label>Titre principal</label>
                        <input type="text" name="main_title" class="form-control" value="{{ @$about->main_title }}">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="summernote" class="form-control">{!! @$about->description !!}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Youtube Video Link</label>
                        <input type="text" name="video_link" class="form-control" value="{{ @$about->video_link }}">
                    </div>
                    <button type="submit" class="btn btn-primary">Modifier</button>
                </form>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function(){
            $('.image-preview').css({
                'background-image': 'url({{ asset(@$about->image) }})',
                'background-size': 'cover',
                'background-position': 'center center'
            })
        })
    </script>
@endpush

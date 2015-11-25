<?php 

namespace CmsCanvas\Http\Controllers\Admin\Content;

use View, Theme, Admin, Redirect, Validator, Request, Input, DB, stdClass, App, Auth, Config;
use CmsCanvas\Http\Controllers\Admin\AdminController;
use CmsCanvas\Models\Content\Entry;
use CmsCanvas\Models\Content\Type;
use CmsCanvas\Models\Language;
use CmsCanvas\Models\Content\Entry\Status;
use CmsCanvas\Models\User;
use CmsCanvas\Models\Content\Revision;
use Carbon\Carbon;
use Content;

class EntryController extends AdminController {

    /**
     * Display all entries
     *
     * @return View
     */
    public function getEntries()
    {
        $content = View::make('cmscanvas::admin.content.entry.entries');

        $filter = Entry::getSessionFilter();
        $orderBy = Entry::getSessionOrderBy();

        $entries = new Entry;
        $entries = $entries->join('content_types', 'entries.content_type_id', '=', 'content_types.id')
            ->leftJoin('permissions', 'content_types.admin_entry_view_permission_id', '=', 'permissions.id')
            ->leftJoin('role_permissions', 'content_types.admin_entry_view_permission_id', '=', 'role_permissions.permission_id')
            ->join('entry_statuses', 'entries.entry_status_id', '=', 'entry_statuses.id')
            ->select(DB::raw('entries.*, content_types.title as content_type_title, entry_statuses.name as entry_status_name'))
            ->distinct()
            ->where(function($query) {
                $query->whereNull('content_types.admin_entry_view_permission_id');
                $roles = Auth::user()->roles;
                if (count($roles) > 0) {
                    $query->orWhereIn('role_permissions.role_id', $roles->lists('id')->all());
                }
            })
            ->applyFilter($filter)
            ->applyOrderBy($orderBy);

        $contentTypes = Type::getAvailableForNewEntry();
        $entryStatuses = Status::orderBy('id', 'asc')->get();
        $viewableContentTypes = Type::getAllViewable();

        $content->entries = $entries->paginate(50);
        $content->filter = new stdClass();
        $content->filter->filter = $filter;
        $content->orderBy = $orderBy;
        $content->contentTypes = $contentTypes;
        $content->viewableContentTypes = $viewableContentTypes;
        $content->entryStatuses = $entryStatuses;

        $this->layout->breadcrumbs = [Request::path() => 'Entries'];
        $this->layout->content = $content;

    }

    /**
     * Saves the filter request to the session
     *
     * @return View
     */
    public function postEntries()
    {
        Entry::processFilterRequest();

        return Redirect::route('admin.content.entry.entries');
    }

    /**
     * Prompts for entry delete verfication for entries posted in the selected array
     *
     * @return View
     */
    public function postDeleteVerify()
    {
        $selected = Input::get('selected');

        if (empty($selected) || ! is_array($selected)) {
            return Redirect::route('admin.content.entry.entries');
        }

        $entries = Entry::whereIn('id', $selected)->get();

        $content = View::make('cmscanvas::admin.content.entry.deleteVerify');
        $content->entries = $entries;

        $this->layout->breadcrumbs = [Request::path() => 'Entries'];
        $this->layout->content = $content;
    }

    /**
     * Deletes entry(s) that are posted in the selected array
     *
     * @return View
     */
    public function postDelete()
    {
        $selected = Input::get('selected');
        $deleteSuccessfulFlag = false;
        $errors = [];

        if (empty($selected) || ! is_array($selected)) {
            return Redirect::route('admin.content.entry.entries')
                ->with('notice', 'You must select at least one group to delete.');
        }

        $selected = array_values($selected);

        foreach ($selected as $entryId) {
            $entry = Entry::find($entryId);

            if ($entry != null) {
                try {
                    $entry->delete();
                    $deleteSuccessfulFlag = true;
                } catch (\CmsCanvas\Exceptions\Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        $redirect = Redirect::route('admin.content.entry.entries');

        if (count($errors) > 0) {
            $redirect->with('error', $errors);
        }

        if ($deleteSuccessfulFlag) {
            if (count($errors) > 0) {
                $message = 'Some of the selected entry(s) were sucessfully deleted.';
            } else {
                $message = 'The selected entry(s) were sucessfully deleted.';
            }

            $redirect->with('message', $message);
        }

        return $redirect;
    }

    /**
     * Display add entry form
     *
     * @return View
     */
    public function getAdd()
    {
        // Routed to getEdit
    }

    /**
     * Create a new entry
     *
     * @return View
     */
    public function postAdd()
    {
        // Routed to postEdit
    }

    /**
     * Display add entry form
     *
     * @return View
     */
    public function getEdit($contentType, $entry = null, $revision = null)
    {
        if ($entry == null) {
            $contentType->checkEntriesAllowed();
            $contentType->checkAdminEntryCreatePermissions();
        } else {
            $entry->contentType->checkAdminEntryEditPermissions();
        }

        $content = View::make('cmscanvas::admin.content.entry.edit');

        $entryStatuses = Status::orderBy('id', 'asc')->get();
        $authors = User::getAuthors();
        $authorOptions = ['' => ''];
        foreach ($authors as $author) {
            $authorOptions[$author->id] = $author->getFullName();
        }

        $contentFields = $contentType->getAllFieldTypeInstances($entry);

        if ($revision != null) {
            $entry->fill($revision->data);
            $contentFields->fill($revision->data);
        }

        $content->entry = $entry;
        $content->fieldViews = $contentFields->getAdminViews();
        $content->entryStatuses = $entryStatuses;
        $content->authorOptions = $authorOptions;
        $content->contentType = $contentType;
        $content->revision = $revision;

        $this->layout->content = $content;
    }

    /**
     * Update an existing entry
     *
     * @return View
     */
    public function postEdit($contentType, $entry = null, $revision = null)
    {
        if ($entry == null) {
            $contentType->checkEntriesAllowed();
            $contentType->checkAdminEntryCreatePermissions();
        } else {
            $entry->contentType->checkAdminEntryEditPermissions();
        }
        
        $contentFields = $contentType->getAllFieldTypeInstances($entry);
        $rules = $contentFields->getValidationRules();

        $rules['title'] = 'required';

        if ($contentType->dynamic_routing_flag) {
            $rules['url_title'] = "required|alpha_dash|max:255"
                ."|unique:entries,url_title,".(($entry == null) ? "NULL" : "{$entry->id}").",id"
                .",content_type_id,{$contentType->id}";
        }

        $attributeNames = $contentFields->getAttributeNames();

        $validator = Validator::make(Input::all(), $rules, [], $attributeNames);

        if ($validator->fails()) {
            if ($entry == null) {
                return Redirect::route('admin.content.entry.add', $contentType->id)
                    ->withInput()
                    ->with('error', $validator->messages()->all());
            } else {
                return Redirect::route('admin.content.entry.edit', [$contentType->id, $entry->id])
                    ->withInput()
                    ->with('error', $validator->messages()->all());
            }
        }

        $createdAt = Carbon::createFromFormat('d/M/Y h:i:s a', Input::get('created_at'), auth::user()->timezone->identifier);
        $createdAt->setTimezone(config::get('app.timezone'));

        $data = Input::all();
        $data['created_at'] = $createdAt;

        $entry = ($entry == null) ? new Entry : $entry;
        $entry->fill($data);
        $entry->content_type_id = $contentType->id;
        $entry->save();

        $contentFields->setEntry($entry);
        $contentFields->fill($data);
        $contentFields->save();

        // Create a revision if max revisions is set
        if ($contentType->max_revisions > 0) {
            $oldRevisions = $entry->revisions()
                ->skip($contentType->max_revisions - 1)
                ->take(25)
                ->get();

            foreach ($oldRevisions as $oldRevision) {
                $oldRevision->delete();
            }

            $currentUser = Auth::user();

            $revision = new Revision;
            $revision->resource_type_id = Revision::ENTRY_RESOURCE_TYPE_ID;
            $revision->resource_id = $entry->id;
            $revision->content_type_id = $contentType->id;
            $revision->author_id = $currentUser->id;
            $revision->author_name = $currentUser->getFullName(); // Saved in case the user record is ever deleted
            $revision->data = $data;
            $revision->save();
        }

        if (Input::get('save_exit')) {
            return Redirect::route('admin.content.entry.entries')
                ->with('message', "{$entry->title} was successfully updated.");
        } else {
            return Redirect::route('admin.content.entry.edit', [$contentType->id, $entry->id])
                ->with('message', "{$entry->title} was successfully updated.");
        }
    }

    /**
     * Generate a thumbnail from the specified image path
     *
     * @return string
     */
    public function postCreateThumbnail()
    {
        return Content::thumbnail(
            Input::get('image_path'), 
            150, 
            150, 
            false, 
            ['no_image' => Theme::asset('images/no_image.jpg')]
        );
    }

}
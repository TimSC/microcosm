<?php
require_once("config.php");
require_once('messagepump.php');
require_once('model-bbox.php');
require_once('modelfactory.php');
require_once('userdetails.php');
require_once('traces.php');
require_once('requestprocessor.php');
require_once('changeset.php');

$messagePump = new MessagePump();

$messagePump->AddListener(Message::MAP_QUERY, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_OBJECT_BY_ID, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_FULL_HISTORY, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_RELATIONS_FOR_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_WAYS_FOR_NODE, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CHECK_ELEMENT_EXISTS, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CURRENT_ELEMENT_VER, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_ELEMENT_BBOX, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CREATE_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::MODIFY_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::DELETE_ELEMENT, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::DUMP, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::PURGE_MAP, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_ELEMENT_FULL_DATA, "MapDatabaseEventHandler", Null);
$messagePump->AddListener(Message::SCRIPT_END, "MapDatabaseEventHandler", Null);

$messagePump->AddListener(Message::CHANGESET_IS_OPEN, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::OPEN_CHANGESET, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::UPDATE_CHANGESET, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CLOSE_CHANGESET, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_UID, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_METADATA, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_SIZE, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::EXPAND_BBOX, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CHANGESET_APPEND_ELEMENT, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::CHANGESET_QUERY, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_CONTENT, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_CHANGESET_CLOSE_TIME, "ChangesetDatabaseEventHandler", Null);
$messagePump->AddListener(Message::SCRIPT_END, "ChangesetDatabaseEventHandler", Null);

if(ENABLE_XAPI)
{
$messagePump->AddListener(Message::XAPI_QUERY, "ModelBboxEventHandler", Null);
$messagePump->AddListener(Message::PURGE_MAP, "ModelBboxEventHandler", Null);
$messagePump->AddListener(Message::ELEMENT_UPDATE_PARENTS, "ModelBboxEventHandler", Null);
$messagePump->AddListener(Message::SCRIPT_END, "ModelBboxEventHandler", Null);
}

$messagePump->AddListener(Message::ELEMENT_UPDATE_DONE, "RichEditEventHandler", Null);
$messagePump->AddListener(Message::ELEMENT_UPDATE_PRE_APPLY, "RichEditEventHandler", Null);
$messagePump->AddListener(Message::SCRIPT_END, "RichEditEventHandler", Null);

$messagePump->AddListener(Message::ELEMENT_UPDATE_PRE_APPLY_RICH_DATA, "RichEditLoggerEventHandler", Null);
$messagePump->AddListener(Message::SCRIPT_END, "RichEditLoggerEventHandler", Null);

$messagePump->AddListener(Message::CHECK_LOGIN, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::USER_ADD, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_USER_INFO, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_USER_PERFERENCES, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::SET_USER_PERFERENCES, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::SET_USER_PERFERENCES_SINGLE, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_USER_PERMISSIONS, "UserDatabaseEventHandler", Null);
$messagePump->AddListener(Message::SCRIPT_END, "UserDatabaseEventHandler", Null);

$messagePump->AddListener(Message::GET_TRACES_IN_BBOX, "TraceDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_TRACE_FOR_USER, "TraceDatabaseEventHandler", Null);
$messagePump->AddListener(Message::INSERT_TRACE_INTO_DB, "TraceDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_TRACE_DETAILS, "TraceDatabaseEventHandler", Null);
$messagePump->AddListener(Message::GET_TRACE_DATA, "TraceDatabaseEventHandler", Null);

$messagePump->AddListener(Message::API_EVENT, "ApiEventHandler", Null);

$messagePump->AddListener(Message::API_CHANGESET_OPEN, "ChangesetEventHandler", Null);
$messagePump->AddListener(Message::API_CHANGESET_UPDATE, "ChangesetEventHandler", Null);
$messagePump->AddListener(Message::API_CHANGESET_CLOSE, "ChangesetEventHandler", Null);
$messagePump->AddListener(Message::API_CHANGESET_UPLOAD, "ChangesetEventHandler", Null);
$messagePump->AddListener(Message::API_GET_CHANGESET_CONTENTS, "ChangesetEventHandler", Null);
$messagePump->AddListener(Message::API_PROCESS_SINGLE_OBJECT, "ChangesetEventHandler", Null);
$messagePump->AddListener(Message::API_CHANGESET_EXPAND, "ChangesetEventHandler", Null);

?>

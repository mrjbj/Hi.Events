import {useGetUsers} from "../../../../../../queries/useGetUsers.ts";
import {Avatar, Badge, Button, Group, Menu, Table, Text} from "@mantine/core";
import classes from "./Users.module.scss";
import {IconDotsVertical, IconEye, IconSend, IconShieldOff, IconUser, IconUserShield} from "@tabler/icons-react";
import {getInitials} from "../../../../../../utilites/helpers.ts";
import {t} from "@lingui/macro";
import {Card} from "../../../../../common/Card";
import {HeadingCard} from "../../../../../common/HeadingCard";
import {relativeDate} from "../../../../../../utilites/dates.ts";
import {useDisclosure} from "@mantine/hooks";
import {modals} from "@mantine/modals";
import {InviteUserModal} from "../../../../../modals/InviteUserModal";
import {EditUserModal} from "../../../../../modals/EditUserModal";
import {User} from "../../../../../../types.ts";
import {useState} from "react";
import {useResendUserInvitation} from "../../../../../../mutations/useResendUserInvitation.ts";
import {showError, showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useDeleteUserInvitation} from "../../../../../../mutations/useDeleteUserInvitation.ts";
import {useUpdateUserSuperAdminStatus} from "../../../../../../mutations/useUpdateUserSuperAdminStatus.ts";
import {useIsCurrentUserSuperAdmin} from "../../../../../../hooks/useIsCurrentUserAdmin.ts";
import {useGetMe} from "../../../../../../queries/useGetMe.ts";
import {LoadingMask} from "../../../../../common/LoadingMask";

const Users = () => {
    const usersQuery = useGetUsers();
    const resendInvitationMutation = useResendUserInvitation();
    const revokeInvitationMutation = useDeleteUserInvitation();
    const superAdminStatusMutation = useUpdateUserSuperAdminStatus();
    const isCurrentUserSuperAdmin = useIsCurrentUserSuperAdmin();
    const {data: me} = useGetMe();
    const users = usersQuery.data?.data;
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const [editModalOpen, {open: openEditModal, close: closeEditModal}] = useDisclosure(false);
    const [selectedUser, setSelectedUser] = useState<User | null>(null);

    const handleEdit = (user: User) => {
        setSelectedUser(user);
        openEditModal();
    }

    const handleResendInvitation = (user: User) => {
        resendInvitationMutation.mutate({
            userId: user.id,
        }, {
            onSuccess: () => {
                showSuccess(t`Invitation resent!`);
            },
            onError: (error) => {
                console.error(error);
                showError(t`Something went wrong! Please try again`);
            }
        });
    }

    const handleRevokeInvitation = (user: User) => {
        revokeInvitationMutation.mutate({
            userId: user.id,
        }, {
            onSuccess: () => {
                showSuccess(t`Invitation revoked!`);
            },
            onError: (error) => {
                console.error(error);
                showError(t`Something went wrong! Please try again`);
            }
        });
    }

    const handleSuperAdminToggle = (user: User, makeSuperAdmin: boolean) => {
        const fullName = user.first_name + ' ' + user.last_name;
        modals.openConfirmModal({
            title: makeSuperAdmin
                ? t`Grant Super Admin to ${fullName}?`
                : t`Revoke Super Admin from ${fullName}?`,
            children: (
                <div style={{fontSize: 14}}>
                    {makeSuperAdmin
                        ? t`Super Admins have COMPLETE SYSTEM ACCESS across every account — they can view all data, impersonate any user, and change platform-wide configuration. Grant this only to people you fully trust.`
                        : t`This will demote the user to Admin across every account they belong to. They will lose access to admin pages like Email Suppressions and Contacts.`}
                </div>
            ),
            labels: {
                confirm: makeSuperAdmin ? t`Grant Super Admin` : t`Revoke Super Admin`,
                cancel: t`Cancel`,
            },
            confirmProps: {color: 'red'},
            onConfirm: () => {
                superAdminStatusMutation.mutate({
                    userId: user.id,
                    isSuperAdmin: makeSuperAdmin,
                }, {
                    onSuccess: () => {
                        showSuccess(makeSuperAdmin
                            ? t`Super Admin role granted`
                            : t`Super Admin role revoked`);
                    },
                    onError: (error: any) => {
                        const message = error?.response?.data?.errors?.is_super_admin?.[0]
                            ?? error?.response?.data?.message
                            ?? t`Something went wrong! Please try again`;
                        showError(message);
                    },
                });
            },
        });
    }

    const statusColor = (status: string) => {
        switch (status) {
            case 'ACTIVE':
                return '';
            case 'INVITED':
                return 'orange';
            case 'INACTIVE':
                return 'red';
            default:
                return 'gray';
        }
    }

    const rows = users?.map((user) => (
        <Table.Tr key={user.id}>
            <Table.Td>
                <Group gap="sm">
                    <Avatar
                        size={40} radius={40}>{getInitials(user.first_name + ' ' + user.last_name)}</Avatar>
                    <div>
                        <Text fz="sm" fw={500}>
                            {user.first_name + ' ' + user.last_name}
                        </Text>
                        <Text fz="xs" c="dimmed">
                            {user.email}
                        </Text>
                    </div>
                </Group>
            </Table.Td>

            <Table.Td>
                <Badge variant="outline" color={user.role === 'SUPERADMIN' ? 'red' : undefined}>
                    <Group gap={5}>
                        {user.role === 'ORGANIZER' && <IconUser size={14}/>}
                        {user.role === 'ADMIN' && <IconUserShield size={14}/>}
                        {user.role === 'SUPERADMIN' && <IconUserShield size={14}/>} {user.role}
                    </Group>
                </Badge>
            </Table.Td>
            <Table.Td>
                {user.last_login_at ? relativeDate(user.last_login_at) : t`Never`}
            </Table.Td>
            <Table.Td>
                <Badge color={statusColor(user.status as string)} variant="light">
                    {user.status}
                </Badge>
            </Table.Td>
            <Table.Td width={'60px'}>
                <Menu shadow="md" width={200}>
                    <Menu.Target>
                        <Button variant={'transparent'}>
                            <IconDotsVertical size={18}/>
                        </Button>
                    </Menu.Target>

                    <Menu.Dropdown>
                        <Menu.Item onClick={() => handleEdit(user)}
                                   leftSection={<IconEye size={14}/>}>
                            {t`Edit user`}
                        </Menu.Item>
                        {user.status === 'INVITED' && (
                            <Menu.Item onClick={() => handleResendInvitation(user)}
                                       leftSection={<IconSend size={14}/>}>
                                {t`Resend invitation`}
                            </Menu.Item>
                        )}
                        {user.status === 'INVITED' && (
                            <Menu.Item color={'red'} onClick={() => handleRevokeInvitation(user)}
                                       leftSection={<IconSend size={14}/>}>
                                {t`Revoke invitation`}
                            </Menu.Item>
                        )}
                        {isCurrentUserSuperAdmin && me?.id !== user.id && user.role !== 'SUPERADMIN' && (
                            <Menu.Item color={'red'}
                                       onClick={() => handleSuperAdminToggle(user, true)}
                                       leftSection={<IconUserShield size={14}/>}>
                                {t`Grant Super Admin`}
                            </Menu.Item>
                        )}
                        {isCurrentUserSuperAdmin && me?.id !== user.id && user.role === 'SUPERADMIN' && (
                            <Menu.Item color={'red'}
                                       onClick={() => handleSuperAdminToggle(user, false)}
                                       leftSection={<IconShieldOff size={14}/>}>
                                {t`Revoke Super Admin`}
                            </Menu.Item>
                        )}
                    </Menu.Dropdown>
                </Menu>
            </Table.Td>
        </Table.Tr>
    ));

    return (
        <>
            <HeadingCard
                heading={t`User Management`}
                subHeading={t`Manage your users and their permissions`}
                buttonText={t`Invite User`}
                buttonAction={openCreateModal}
            />

            <Card style={{padding: 0, position: 'relative'}}>
                <LoadingMask/>
                <Table.ScrollContainer className={classes.table} minWidth={800}>
                    <Table verticalSpacing="sm">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`User`}</Table.Th>
                                <Table.Th>{t`Role`}</Table.Th>
                                <Table.Th>{t`Last login`}</Table.Th>
                                <Table.Th>{t`Status`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>{rows}</Table.Tbody>
                    </Table>
                </Table.ScrollContainer>
            </Card>
            {createModalOpen && <InviteUserModal onClose={closeCreateModal}/>}
            {(editModalOpen && selectedUser) && <EditUserModal user={selectedUser} onClose={closeEditModal}/>}
        </>
    );
}

export default Users;